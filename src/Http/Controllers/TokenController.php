<?php

declare(strict_types=1);

namespace Atannex\Bridge\Http\Controllers;

use Atannex\Bridge\Authentication\PrincipalResolver;
use Atannex\Bridge\Authentication\TokenAuthenticator;
use Atannex\Bridge\Authentication\UserPresenter;
use Atannex\Bridge\Contracts\TwoFactorVerifier;
use Atannex\Bridge\Events\TokenIssued;
use Atannex\Bridge\Events\TokenRevoked;
use Atannex\Bridge\Events\TwoFactorFailed;
use Atannex\Bridge\Exceptions\BridgeException;
use Atannex\Bridge\Http\Envelope;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;

/**
 * Mobile bearer-token login on top of Sanctum.
 *
 * Tokens are only issued after the password checks out and, for users with
 * two-factor authentication, after a valid second factor. Bridge never
 * stores passwords or tokens itself: users come from the app's user
 * provider and tokens are Sanctum personal access tokens.
 */
final class TokenController
{
    private const CHALLENGE_PREFIX = 'bridge:two-factor:';

    /**
     * @param  array<string, mixed>  $config  bridge.auth
     */
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly RateLimiter $limiter,
        private readonly Cache $cache,
        private readonly TwoFactorVerifier $twoFactor,
        private readonly UserPresenter $presenter,
        private readonly TokenAuthenticator $authenticator,
        private readonly array $config,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $key = 'bridge:login:'.hash('sha256', Str::lower($data['email']).'|'.$request->ip());
        $max = (int) ($this->config['tokens']['max_attempts'] ?? 5);

        if ($this->limiter->tooManyAttempts($key, $max)) {
            event(new Lockout($request));

            throw BridgeException::rateLimited($this->limiter->availableIn($key));
        }

        $guard = $this->config['tokens']['guard'] ?? 'web';
        $user = $this->authenticator->authenticate($request, $guard);

        if ($user === null) {
            $this->limiter->hit($key, (int) ($this->config['tokens']['decay_seconds'] ?? 60));
            event(new Failed($guard, null, ['email' => $data['email']]));

            throw BridgeException::validation(['email' => [trans('auth.failed')]]);
        }

        $this->limiter->clear($key);

        if ($this->twoFactor->required($user)) {
            // The password is known: the remaining brake on the second factor
            // is per user, and a correct password does not reset it.
            $userKey = $this->twoFactorUserKey($user);

            if ($this->limiter->tooManyAttempts($userKey, $this->twoFactorUserLimit())) {
                event(new Lockout($request));

                throw BridgeException::rateLimited($this->limiter->availableIn($userKey));
            }

            return $this->challenge($request, $user, $data['device_name']);
        }

        return $this->issue($request, $user, $data['device_name'], false);
    }

    public function twoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'size:64'],
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'max:16'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:64'],
        ]);

        $ipKey = 'bridge:two-factor-ip:'.hash('sha256', (string) $request->ip());

        if ($this->limiter->tooManyAttempts($ipKey, 20)) {
            throw BridgeException::rateLimited($this->limiter->availableIn($ipKey));
        }

        $id = self::CHALLENGE_PREFIX.hash('sha256', $data['challenge']);
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Two-factor login needs a cache store that supports locks.');
        }

        $lock = $store->lock($id.':lock', 10);

        // Concurrent submissions of the same challenge cannot both win.
        if (! $lock->get()) {
            throw BridgeException::twoFactorFailed();
        }

        try {
            $pending = $this->cache->get($id);

            if (! is_array($pending)) {
                $this->limiter->hit($ipKey, 60);

                throw BridgeException::twoFactorFailed();
            }

            $user = $this->provider($this->config['tokens']['guard'] ?? 'web')->retrieveById($pending['user']);

            // Over the per-user limit: no code is checked, even a right one.
            if ($user !== null && $this->limiter->tooManyAttempts($this->twoFactorUserKey($user), $this->twoFactorUserLimit())) {
                $this->cache->forget($id);

                throw BridgeException::rateLimited($this->limiter->availableIn($this->twoFactorUserKey($user)));
            }

            $passed = $user !== null && (isset($data['code'])
                ? $this->twoFactor->verifyCode($user, $data['code'])
                : $this->twoFactor->useRecoveryCode($user, (string) $data['recovery_code']));

            if (! $passed) {
                $this->limiter->hit($ipKey, 60);

                if ($user !== null) {
                    $this->limiter->hit($this->twoFactorUserKey($user), (int) ($this->config['tokens']['two_factor_user_decay'] ?? 900));
                }

                $attempts = $pending['attempts'] + 1;
                $burned = $attempts >= (int) ($this->config['tokens']['two_factor_attempts'] ?? 5);

                if ($burned) {
                    $this->cache->forget($id);
                } else {
                    $this->cache->put($id, ['attempts' => $attempts] + $pending, CarbonImmutable::createFromTimestamp($pending['expires']));
                }

                if ($user !== null) {
                    event(new TwoFactorFailed($user->getAuthIdentifier(), $request->ip(), $burned));
                }

                throw BridgeException::twoFactorFailed();
            }

            $this->cache->forget($id);
            $this->limiter->clear($this->twoFactorUserKey($user));
        } finally {
            $lock->release();
        }

        return $this->issue($request, $user, $pending['device'], true);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $this->auth->guard('sanctum')->user();

        if ($user === null) {
            throw BridgeException::unauthenticated();
        }

        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        // A cookie session has a transient token; it ends through the app's
        // own logout route, not here.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
            event(new TokenRevoked($user->getAuthIdentifier(), $request->ip()));
        }

        return Envelope::success($request, null);
    }

    /**
     * Swaps the bearer token that sent the request for a new one with the
     * same name and abilities and a fresh expiry, then revokes the old one.
     * Only a live personal access token can be refreshed: an expired or
     * revoked token gets 401 and the user signs in again.
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $this->auth->guard('sanctum')->user();
        $token = $user !== null && method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        if (! $token instanceof PersonalAccessToken) {
            throw BridgeException::unauthenticated();
        }

        // Two refreshes racing with the same token: only the one that deletes
        // it gets a new token, so one token never turns into two.
        $deleted = PersonalAccessToken::query()->whereKey($token->getKey())->delete();

        if ($deleted !== 1) {
            throw BridgeException::unauthenticated();
        }

        event(new TokenRevoked($user->getAuthIdentifier(), $request->ip()));

        return $this->issue($request, $user, (string) $token->getAttribute('name'), false, $token->abilities ?? ['*']);
    }

    public function user(Request $request, PrincipalResolver $principals): JsonResponse
    {
        [$user] = $principals->resolve($request);

        if ($user === null) {
            throw BridgeException::unauthenticated();
        }

        return Envelope::success($request, $this->presenter->present($user));
    }

    private function challenge(Request $request, Authenticatable $user, string $device): JsonResponse
    {
        $challenge = Str::random(64);
        $ttl = (int) ($this->config['tokens']['two_factor_ttl'] ?? 300);
        $expires = CarbonImmutable::now()->addSeconds($ttl);

        $this->cache->put(self::CHALLENGE_PREFIX.hash('sha256', $challenge), [
            'user' => $user->getAuthIdentifier(),
            'device' => $device,
            'attempts' => 0,
            'expires' => $expires->getTimestamp(),
        ], $expires);

        return Envelope::success($request, [
            'status' => 'two_factor_required',
            'challenge' => $challenge,
            'expires_at' => $expires->utc()->format(DateTimeInterface::ATOM),
        ]);
    }

    /**
     * @param  list<string>|null  $abilities  null = the configured abilities
     */
    private function issue(Request $request, Authenticatable $user, string $device, bool $afterTwoFactor, ?array $abilities = null): JsonResponse
    {
        if (! method_exists($user, 'createToken')) {
            throw new LogicException('Mobile tokens need laravel/sanctum and the HasApiTokens trait on the user model.');
        }

        $minutes = $this->config['tokens']['expiration'] ?? null;
        $token = $user->createToken(
            $device,
            $abilities ?? $this->config['tokens']['abilities'] ?? ['*'],
            $minutes !== null ? now()->addMinutes((int) $minutes) : null,
        );

        event(new TokenIssued($user->getAuthIdentifier(), $device, $request->ip(), $afterTwoFactor));

        return Envelope::success($request, [
            'status' => 'authenticated',
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $this->expiresAt($token->accessToken),
            'user' => $this->presenter->present($user),
        ]);
    }

    /**
     * When the token stops working: its own expiry, else Sanctum's global
     * `expiration` counted from creation, else null for never.
     */
    private function expiresAt(PersonalAccessToken $token): ?string
    {
        $at = $token->getAttribute('expires_at');
        $global = config('sanctum.expiration');

        if ($at === null && $global !== null) {
            $at = $token->getAttribute('created_at')?->copy()->addMinutes((int) $global);
        }

        return $at instanceof DateTimeInterface ? CarbonImmutable::instance($at)->utc()->format(DateTimeInterface::ATOM) : null;
    }

    private function twoFactorUserKey(Authenticatable $user): string
    {
        return 'bridge:two-factor-user:'.hash('sha256', $user::class.'|'.$user->getAuthIdentifier());
    }

    private function twoFactorUserLimit(): int
    {
        return (int) ($this->config['tokens']['two_factor_user_attempts'] ?? 5);
    }

    private function provider(string $guard): UserProvider
    {
        $name = config("auth.guards.{$guard}.provider");
        $provider = $name !== null ? $this->auth->createUserProvider($name) : null;

        if ($provider === null) {
            throw new LogicException("Bridge token login uses guard [{$guard}], which has no user provider.");
        }

        return $provider;
    }
}
