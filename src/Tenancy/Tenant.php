<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tenancy;

/**
 * The tenant a call runs in, as decided by the server.
 */
final readonly class Tenant
{
    public function __construct(
        public string|int $key,
        public mixed $model = null,
    ) {}
}
