<?php

declare(strict_types=1);

namespace Atannex\Bridge\Contracts;

use Atannex\Bridge\Tenancy\Tenant;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Works out which tenant a call runs in, from trusted server state.
 *
 * $requested is the client's X-Bridge-Tenant header: a selection request,
 * never proof. Return the tenant only if $user may act in it; return null
 * otherwise. Bridge itself refuses a call whose requested tenant was not
 * returned, so a resolver cannot accidentally honour a forged selection by
 * returning some other tenant.
 */
interface TenantResolver
{
    public function resolve(Request $request, ?Authenticatable $user, ?string $requested): ?Tenant;
}
