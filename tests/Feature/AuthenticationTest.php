<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Feature;

use Atannex\Bridge\Events\TokenIssued;
use Atannex\Bridge\Events\TokenRevoked;
use Atannex\Bridge\Facades\Bridge;
use Atannex\Bridge\Tests\Fixtures\User;
use Atannex\Bridge\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

final class AuthenticationTest extends TestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    public function test_password_login_issues_a_bearer_token_that_authenticates_calls(): void
    {
        Event::fake([TokenIssued::class]);
        $user = $this->user(['email' => 'alice@example.com']);

        $response = $this->postJson('/bridge/v1/auth/token', [
            'email' => 'alice@example.com',
            'password' => 'password',
            'device_name' => 'Pixel 9',
        ])->assertOk()
            ->assertJsonPath('data.status', 'authenticated')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user', ['id' => $user->id, 'name' => $user->name, 'email' => 'alice@example.com']);

        $token = $response->json('data.token');
        $this->assertIsString($token);
        Event::assertDispatched(TokenIssued::class, fn (TokenIssued $e) => $e->deviceName === 'Pixel 9' && ! $e->afterTwoFactor);

        $this->app['auth']->forgetGuards();

        $this->call_('me.show', [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.guard', 'sanctum');
    }

    public function test_wrong_credentials_get_one_generic_message(): void
    {
        $this->user(['email' => 'alice@example.com']);

        $wrongPassword = $this->postJson('/bridge/v1/auth/token', ['email' => 'alice@example.com', 'password' => 'nope', 'device_name' => 'x'])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $noSuchUser = $this->postJson('/bridge/v1/auth/token', ['email' => 'ghost@example.com', 'password' => 'nope', 'device_name' => 'x'])
            ->assertStatus(422);

        $this->assertSame($wrongPassword->json('error.details'), $noSuchUser->json('error.details'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_is_rate_limited_per_email_and_ip(): void
    {
        $this->user(['email' => 'alice@example.com']);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/bridge/v1/auth/token', ['email' => 'alice@example.com', 'password' => 'bad', 'device_name' => 'x'])->assertStatus(422);
        }

        $this->postJson('/bridge/v1/auth/token', ['email' => 'alice@example.com', 'password' => 'password', 'device_name' => 'x'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');
    }

    public function test_missing_fields_are_validation_errors_in_protocol_form(): void
    {
        $this->postJson('/bridge/v1/auth/token', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['email', 'password', 'device_name']], 'meta' => ['request_id']]);
    }

    public function test_two_factor_users_get_a_challenge_and_no_token_until_the_code_is_right(): void
    {
        $user = $this->twoFactorUser();

        $challenge = $this->postJson('/bridge/v1/auth/token', ['email' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])
            ->assertOk()
            ->assertJsonPath('data.status', 'two_factor_required')
            ->assertJsonMissingPath('data.token')
            ->json('data.challenge');

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/bridge/v1/auth/two-factor', ['challenge' => $challenge, 'code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TWO_FACTOR_FAILED');

        $code = (new Google2FA)->getCurrentOtp(self::SECRET);

        $this->postJson('/bridge/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('data.status', 'authenticated')
            ->assertJsonStructure(['data' => ['token']]);

        // Single use.
        $this->postJson('/bridge/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $code])->assertStatus(422);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_a_recovery_code_works_once(): void
    {
        $user = $this->twoFactorUser();
        $challenge = fn () => $this->postJson('/bridge/v1/auth/token', ['email' => $user->email, 'password' => 'password', 'device_name' => 'x'])->json('data.challenge');

        $this->postJson('/bridge/v1/auth/two-factor', ['challenge' => $challenge(), 'recovery_code' => 'recovery-one'])->assertOk();
        $this->postJson('/bridge/v1/auth/two-factor', ['challenge' => $challenge(), 'recovery_code' => 'recovery-one'])->assertStatus(422);
    }

    public function test_a_challenge_burns_after_too_many_wrong_codes(): void
    {
        $user = $this->twoFactorUser();
        $challenge = $this->postJson('/bridge/v1/auth/token', ['email' => $user->email, 'password' => 'password', 'device_name' => 'x'])->json('data.challenge');

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/bridge/v1/auth/two-factor', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);
        }

        $this->postJson('/bridge/v1/auth/two-factor', ['challenge' => $challenge, 'code' => (new Google2FA)->getCurrentOtp(self::SECRET)])
            ->assertStatus(422);
    }

    public function test_second_factor_guesses_are_limited_per_user_across_challenges(): void
    {
        $user = $this->twoFactorUser();
        $login = fn () => $this->postJson('/bridge/v1/auth/token', ['email' => $user->email, 'password' => 'password', 'device_name' => 'x']);

        // Five wrong codes spread over fresh challenges (and over the per-challenge cap).
        foreach (range(1, 5) as $round) {
            $challenge = $login()->assertOk()->json('data.challenge');
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$round}"])
                ->postJson('/bridge/v1/auth/two-factor', ['challenge' => $challenge, 'code' => '000000'])
                ->assertStatus(422);
        }

        // The password is still right, but no new challenge is handed out.
        $login()->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_live_challenge_stops_accepting_codes_once_the_user_is_over_the_limit(): void
    {
        $user = $this->twoFactorUser();
        $challenge = $this->postJson('/bridge/v1/auth/token', ['email' => $user->email, 'password' => 'password', 'device_name' => 'x'])->json('data.challenge');
        $other = $this->postJson('/bridge/v1/auth/token', ['email' => $user->email, 'password' => 'password', 'device_name' => 'x'])->json('data.challenge');

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/bridge/v1/auth/two-factor', ['challenge' => $other, 'code' => '000000']);
        }

        $this->postJson('/bridge/v1/auth/two-factor', ['challenge' => $challenge, 'code' => (new Google2FA)->getCurrentOtp(self::SECRET)])
            ->assertStatus(429);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_token_login_follows_the_apps_login_rules(): void
    {
        $this->user(['email' => 'banned@example.com']);

        // Fortify's authenticateUsing() is honoured when set...
        Fortify::authenticateUsing(fn () => null);
        $this->postJson('/bridge/v1/auth/token', ['email' => 'banned@example.com', 'password' => 'password', 'device_name' => 'x'])->assertStatus(422);
        Fortify::$authenticateUsingCallback = null;

        // ...and Bridge's own hook takes precedence.
        Bridge::authenticateTokensUsing(fn () => null);
        $this->postJson('/bridge/v1/auth/token', ['email' => 'banned@example.com', 'password' => 'password', 'device_name' => 'x'])->assertStatus(422);
        Bridge::authenticateTokensUsing(null);

        $this->postJson('/bridge/v1/auth/token', ['email' => 'banned@example.com', 'password' => 'password', 'device_name' => 'x'])->assertOk();
    }

    public function test_mobile_tokens_expire_after_thirty_days_by_default(): void
    {
        $this->user(['email' => 'alice@example.com']);
        $this->postJson('/bridge/v1/auth/token', ['email' => 'alice@example.com', 'password' => 'password', 'device_name' => 'x'])->assertOk();

        $expires = PersonalAccessToken::query()->sole()->expires_at;
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $expires->getTimestamp(), 60);
    }

    public function test_logout_revokes_the_token(): void
    {
        Event::fake([TokenRevoked::class]);
        $this->user(['email' => 'alice@example.com']);
        $token = $this->postJson('/bridge/v1/auth/token', ['email' => 'alice@example.com', 'password' => 'password', 'device_name' => 'x'])->json('data.token');
        $this->app['auth']->forgetGuards();

        $this->postJson('/bridge/v1/auth/logout', [], ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonPath('data', null);
        $this->app['auth']->forgetGuards();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        Event::assertDispatched(TokenRevoked::class);

        $this->call_('me.show', [], ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $this->postJson('/bridge/v1/auth/logout', [], ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_login_reports_when_the_token_expires(): void
    {
        $this->user(['email' => 'alice@example.com']);
        $expires = $this->postJson('/bridge/v1/auth/token', ['email' => 'alice@example.com', 'password' => 'password', 'device_name' => 'x'])
            ->json('data.expires_at');

        $this->assertIsString($expires);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), strtotime($expires), 60);
    }

    public function test_refresh_swaps_a_live_token_for_a_new_one(): void
    {
        Event::fake([TokenIssued::class, TokenRevoked::class]);
        $user = $this->user(['email' => 'alice@example.com']);
        $old = $this->postJson('/bridge/v1/auth/token', ['email' => 'alice@example.com', 'password' => 'password', 'device_name' => 'Pixel 9'])->json('data.token');
        $this->app['auth']->forgetGuards();
        $this->travel(10)->days();

        $new = $this->postJson('/bridge/v1/auth/refresh', [], ['Authorization' => 'Bearer '.$old])
            ->assertOk()
            ->assertJsonPath('data.status', 'authenticated')
            ->assertJsonPath('data.user.id', $user->id)
            ->json('data.token');
        $this->app['auth']->forgetGuards();

        $this->assertNotSame($old, $new);
        $token = PersonalAccessToken::query()->sole();
        $this->assertSame('Pixel 9', $token->name);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $token->expires_at->getTimestamp(), 60);
        Event::assertDispatched(TokenRevoked::class);

        $this->call_('me.show', [], ['Authorization' => 'Bearer '.$old])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->call_('me.show', [], ['Authorization' => 'Bearer '.$new])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/bridge/v1/auth/refresh', [], ['Authorization' => 'Bearer '.$old])->assertUnauthorized();
    }

    public function test_an_expired_token_cannot_be_refreshed(): void
    {
        $this->user(['email' => 'alice@example.com']);
        $token = $this->postJson('/bridge/v1/auth/token', ['email' => 'alice@example.com', 'password' => 'password', 'device_name' => 'x'])->json('data.token');
        $this->app['auth']->forgetGuards();
        $this->travel(31)->days();

        $this->postJson('/bridge/v1/auth/refresh', [], ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_refresh_needs_a_bearer_token(): void
    {
        $this->postJson('/bridge/v1/auth/refresh')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user(['email' => 'alice@example.com']));
        $this->postJson('/bridge/v1/auth/refresh')->assertUnauthorized();
    }

    public function test_the_user_endpoint_shows_only_allowlisted_fields(): void
    {
        $user = $this->user(['secret_note' => 'private']);

        $data = $this->actingAs($user, 'web')->getJson('/bridge/v1/auth/user')->assertOk()->json('data');

        $this->assertSame(['id' => $user->id, 'name' => $user->name, 'email' => $user->email], $data);

        $this->app['auth']->forgetGuards();
        $this->getJson('/bridge/v1/auth/user')->assertUnauthorized();
    }

    public function test_session_authenticated_users_reach_operations_through_the_sanctum_guard(): void
    {
        $user = $this->user();

        // A cookie session on the web guard, as Sanctum's SPA mode sees it.
        $this->actingAs($user, 'web')->call_('me.show')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    private function twoFactorUser(): User
    {
        return $this->user([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt(self::SECRET),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-one', 'recovery-two'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
