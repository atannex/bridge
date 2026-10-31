<?php

declare(strict_types=1);

namespace Atannex\Bridge\Authentication;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;
use LogicException;

/**
 * Decides whether email + password identify a user for mobile token login.
 *
 * In order: a callback from Bridge::authenticateTokensUsing(); else Fortify's
 * Fortify::authenticateUsing() callback, so rules the app already applies to
 * web logins (banned users, unverified email, ...) apply to tokens too; else
 * the guard's user provider.
 */
final class TokenAuthenticator
{
    /** A real bcrypt hash, checked when the user does not exist so timing does not reveal it. */
    private const DUMMY_HASH = '$2y$12$0rc/qxK6MC06OJIN1YcsCexUkmc6MldiPEOFnlCB5YcQRdgXA9w2m';

    private ?Closure $custom = null;

    public function __construct(private readonly AuthFactory $auth) {}

    /**
     * @param  (Closure(Request): ?Authenticatable)|null  $callback
     */
    public function using(?Closure $callback): void
    {
        $this->custom = $callback;
    }

    public function authenticate(Request $request, string $guard): ?Authenticatable
    {
        if ($this->custom !== null) {
            return ($this->custom)($request);
        }

        if (class_exists(Fortify::class) && Fortify::$authenticateUsingCallback !== null) {
            $user = (Fortify::$authenticateUsingCallback)($request);

            return $user instanceof Authenticatable ? $user : null;
        }

        $name = config("auth.guards.{$guard}.provider");
        $provider = $name !== null ? $this->auth->createUserProvider($name) : null;

        if ($provider === null) {
            throw new LogicException("Bridge token login uses guard [{$guard}], which has no user provider.");
        }

        $user = $provider->retrieveByCredentials(['email' => $request->input('email')]);

        if ($user === null) {
            Hash::check((string) $request->input('password'), self::DUMMY_HASH);

            return null;
        }

        return $provider->validateCredentials($user, ['password' => (string) $request->input('password')]) ? $user : null;
    }
}
