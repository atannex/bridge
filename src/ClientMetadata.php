<?php

declare(strict_types=1);

namespace Atannex\Bridge;

/**
 * What the client says about itself. Untrusted: fit for logs and analytics,
 * never for decisions.
 */
final readonly class ClientMetadata
{
    public function __construct(
        public ?string $client,
        public ?string $ip,
        public ?string $userAgent,
        public ?string $requestedTenant,
    ) {}
}
