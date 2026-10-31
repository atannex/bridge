<?php

declare(strict_types=1);

namespace Atannex\Bridge;

/**
 * Protocol constants shared by the server pieces. See PROTOCOL.md.
 */
final class Protocol
{
    public const VERSION = '1';

    /** @var list<string> */
    public const SUPPORTED_VERSIONS = ['1'];

    public const VERSION_HEADER = 'X-Bridge-Version';

    public const TENANT_HEADER = 'X-Bridge-Tenant';

    public const CLIENT_HEADER = 'X-Bridge-Client';

    public const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    public const MAX_JSON_DEPTH = 64;
}
