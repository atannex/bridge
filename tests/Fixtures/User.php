<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property bool $is_admin
 * @property int|null $team_id
 */
class User extends Authenticatable
{
    use HasApiTokens;
    use TwoFactorAuthenticatable;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return ['is_admin' => 'boolean', 'two_factor_confirmed_at' => 'datetime'];
    }
}
