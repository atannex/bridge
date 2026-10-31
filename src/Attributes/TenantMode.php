<?php

declare(strict_types=1);

namespace Atannex\Bridge\Attributes;

enum TenantMode: string
{
    /** The operation ignores tenants. */
    case None = 'none';

    /** Resolve a tenant if there is one. */
    case Optional = 'optional';

    /** Refuse the call without a tenant. */
    case Required = 'required';
}
