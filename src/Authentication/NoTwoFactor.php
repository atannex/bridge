<?php

declare(strict_types=1);

namespace Atannex\Bridge\Authentication;

use Atannex\Bridge\Contracts\TwoFactorVerifier;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Used when no two-factor system is installed: never requires one, and
 * never accepts a code.
 */
final class NoTwoFactor implements TwoFactorVerifier
{
    public function required(Authenticatable $user): bool
    {
        return false;
    }

    public function verifyCode(Authenticatable $user, string $code): bool
    {
        return false;
    }

    public function useRecoveryCode(Authenticatable $user, string $code): bool
    {
        return false;
    }
}
