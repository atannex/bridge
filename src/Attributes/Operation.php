<?php

declare(strict_types=1);

namespace Atannex\Bridge\Attributes;

use Attribute;

/**
 * Marks an Action class as a Bridge operation for discovery. Discovery only
 * looks in the namespaces listed in config('bridge.discovery').
 *
 *     #[Operation('orders.create', access: Access::Authenticated, idempotency: Idempotency::Idempotent)]
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Operation
{
    /**
     * @param  string|null  $can  Gate ability checked before validation, e.g. 'viewAny'.
     * @param  class-string|null  $on  Model class passed to the ability, e.g. Order::class.
     * @param  string|null  $rateLimit  Named limiter (RateLimiter::for). null uses config default; 'none' turns it off.
     * @param  list<string>  $middleware  Extra route middleware for this operation only.
     */
    public function __construct(
        public string $name,
        public Access $access = Access::Protected,
        public ?string $can = null,
        public ?string $on = null,
        public Idempotency $idempotency = Idempotency::Unsafe,
        public TenantMode $tenant = TenantMode::None,
        public ?string $rateLimit = null,
        public array $middleware = [],
        public string $version = '1.0',
        public ?string $deprecated = null,
        public ?string $description = null,
    ) {}
}
