<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Fixtures;

use Atannex\Bridge\Attributes\Access;
use Atannex\Bridge\Attributes\Idempotency;
use Atannex\Bridge\Attributes\TenantMode;
use Atannex\Bridge\Facades\Bridge;
use Atannex\Bridge\Tenancy\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

final class FixtureOperations
{
    public static function register(): void
    {
        Bridge::action('test.echo', Actions\EchoInput::class, ['access' => Access::Guest, 'idempotency' => Idempotency::Safe]);
        Bridge::action('users.list', Actions\ListUsers::class, ['access' => Access::Guest, 'idempotency' => Idempotency::Safe]);
        Bridge::action('users.show', Actions\ShowUser::class, ['access' => Access::Guest, 'idempotency' => Idempotency::Safe, 'description' => 'One user, public fields only.']);
        Bridge::action('test.leaky', Actions\Leaky::class, ['access' => Access::Authenticated]);
        Bridge::action('test.boom', Actions\Boom::class, ['access' => Access::Guest]);
        Bridge::action('me.show', Actions\Me::class, ['access' => Access::Authenticated, 'idempotency' => Idempotency::Safe]);
        Bridge::action('admin.stats', Actions\AdminStats::class, ['can' => 'view-admin-stats']);
        Bridge::action('test.locked', Actions\Locked::class);
        Bridge::action('documents.show', Actions\ShowDocument::class, ['idempotency' => Idempotency::Safe]);
        Bridge::action('documents.create', Actions\CreateDocument::class, ['access' => Access::Authenticated, 'idempotency' => Idempotency::Idempotent]);
        Bridge::action('team.info', Actions\TeamInfo::class, ['access' => Access::Authenticated, 'tenant' => TenantMode::Required]);
        Bridge::action('avatar.upload', Actions\UploadAvatar::class, ['access' => Access::Authenticated]);
        Bridge::action('test.conflict', Actions\Conflicting::class, ['access' => Access::Guest]);
        Bridge::action('test.old', Actions\Nothing::class, ['access' => Access::Guest, 'deprecated' => 'Use test.echo instead.']);
        Bridge::action('test.limited', Actions\Nothing::class, ['access' => Access::Guest, 'rateLimit' => 'tight']);
        Bridge::action('test.void', Actions\Nothing::class, ['access' => Access::Guest]);
        Bridge::event(OrderUpdated::class);

        Gate::define('view-admin-stats', fn (User $user) => $user->is_admin);

        RateLimiter::for('tight', fn ($request) => Limit::perMinute(2)->by($request->ip()));

        // Teams: a user may act only in their own team.
        Bridge::resolveTenantsUsing(function ($request, $user, ?string $requested): ?Tenant {
            if ($user === null || $user->team_id === null) {
                return null;
            }

            $own = (string) $user->team_id;

            if ($requested !== null && $requested !== $own) {
                return null;
            }

            return new Tenant($own);
        });
    }
}
