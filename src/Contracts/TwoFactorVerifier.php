<?php

declare(strict_types=1);

namespace Atannex\Bridge\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Second-factor checks for mobile token login. The Fortify implementation is
 * used when Fortify is installed; bind your own for anything else.
 */
interface TwoFactorVerifier
{
    /** Whether this user must pass a second factor before getting a token. */
    public function required(Authenticatable $user): bool;

    public function verifyCode(Authenticatable $user, string $code): bool;

    /** Checks and consumes a recovery code. */
    public function useRecoveryCode(Authenticatable $user, string $code): bool;
}
