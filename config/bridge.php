<?php

return [

    /*
    | Routes. Everything Bridge serves sits under this prefix. The middleware
    | group runs before Bridge; for cookie sessions (Sanctum SPA) use 'api'
    | with statefulApi() enabled, or 'web'.
    */
    'routes' => [
        'enabled' => true,
        'prefix' => env('BRIDGE_PREFIX', 'bridge/v1'),
        'middleware' => ['api'],
        'domain' => null,
    ],

    /*
    | Guards tried, in order, to find the signed-in user. null means the
    | app's default guard. With Sanctum, ['sanctum'] covers both SPA
    | sessions and bearer tokens.
    */
    'guards' => null,

    /*
    | Classes carrying #[Operation] / #[Event] are discovered only in these
    | namespaces (namespace => directory). Run `php artisan bridge:cache` in
    | production so discovery never runs on a request.
    */
    'discovery' => [
        // 'App\\Bridge' => app_path('Bridge'),
    ],

    'cache_path' => null, // default: bootstrap/cache/bridge.php

    /*
    | Default named rate limiter for every operation (RateLimiter::for).
    | Bridge defines 'bridge' (120/min per user or IP) if the app does not.
    | Set to null to apply no default.
    */
    'rate_limit' => 'bridge',

    /*
    | Refuse plain-HTTP calls. On by default in production.
    */
    'require_https' => env('BRIDGE_REQUIRE_HTTPS', env('APP_ENV') === 'production'),

    'limits' => [
        'json_bytes' => 1024 * 1024,
        'multipart_bytes' => 20 * 1024 * 1024,
        'max_files' => 20,
    ],

    /*
    | Locales a client may pick with Accept-Language. null leaves the app
    | locale alone.
    */
    'locales' => null,

    'serialization' => [
        'timezone' => 'UTC',
    ],

    'tenancy' => [
        'header' => 'X-Bridge-Tenant',
        // A class implementing Atannex\Bridge\Contracts\TenantResolver, or
        // register one with Bridge::resolveTenantsUsing().
        'resolver' => null,
    ],

    'idempotency' => [
        'store' => null, // cache store; null = default
        'ttl' => 86400,
        // How long a running call holds its key. A retry arriving while the
        // first call is still running gets IDEMPOTENCY_IN_PROGRESS; keep this
        // longer than your slowest idempotent operation.
        'lock_seconds' => 300,
    ],

    'files' => [
        // Disks Bridge will serve Download links from.
        'disks' => ['local'],
        'ttl' => 300,
    ],

    'auth' => [
        // Fields /auth/user and token login return about the user.
        'user_fields' => ['id', 'name', 'email'],

        'user_endpoint' => true,

        /*
        | Mobile bearer tokens (needs laravel/sanctum). Off by default.
        */
        'tokens' => [
            'enabled' => env('BRIDGE_TOKENS', false),
            'guard' => 'web', // whose user provider checks the password
            'abilities' => ['*'],
            // Minutes. Mobile tokens expire after 30 days unless you change it;
            // null defers to Sanctum's own `expiration` (which defaults to never).
            'expiration' => 60 * 24 * 30,
            'max_attempts' => 5,
            'decay_seconds' => 60,
            'two_factor_ttl' => 300,
            'two_factor_attempts' => 5,       // wrong codes per challenge
            'two_factor_user_attempts' => 5,  // wrong codes per user, across challenges
            'two_factor_user_decay' => 900,   // seconds that per-user count lasts
        ],
    ],

    'generate' => [
        'output' => null, // default: resource_path('js/generated/bridge.ts')
        'interface' => 'AppBridge',
        'module' => '@atannex/bridge',
    ],

    /*
    | Log channel for one structured line per call (no input, output or
    | credentials). null turns it off.
    */
    'log_channel' => env('BRIDGE_LOG_CHANNEL'),
];
