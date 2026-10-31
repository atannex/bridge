<?php

declare(strict_types=1);

namespace Atannex\Bridge\Authentication;

use Atannex\Bridge\Contracts\TwoFactorVerifier;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Fortify;

/**
 * Checks second factors exactly the way Fortify's own login does: the TOTP
 * secret and recovery codes on the user, with Fortify's provider (which also
 * refuses a code that was already used).
 */
final class FortifyTwoFactorVerifier implements TwoFactorVerifier
{
    public function __construct(private readonly TwoFactorAuthenticationProvider $provider) {}

    public function required(Authenticatable $user): bool
    {
        if (! $user instanceof Model || ! method_exists($user, 'hasEnabledTwoFactorAuthentication') || ! $user->hasEnabledTwoFactorAuthentication()) {
            return false;
        }

        // Fortify only enforces confirmed two-factor setups when confirmation is on.
        return ! Fortify::confirmsTwoFactorAuthentication() || $user->getAttribute('two_factor_confirmed_at') !== null;
    }

    public function verifyCode(Authenticatable $user, string $code): bool
    {
        if (! $user instanceof Model || ! $this->required($user) || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        return $this->provider->verify(Fortify::currentEncrypter()->decrypt($user->getAttribute('two_factor_secret')), $code);
    }

    public function useRecoveryCode(Authenticatable $user, string $code): bool
    {
        if (! $this->required($user) || ! method_exists($user, 'recoveryCodes') || ! method_exists($user, 'replaceRecoveryCode')) {
            return false;
        }

        foreach ($user->recoveryCodes() as $known) {
            if (hash_equals($known, $code)) {
                $user->replaceRecoveryCode($code);
                event(new RecoveryCodeReplaced($user, $code));

                return true;
            }
        }

        return false;
    }
}
