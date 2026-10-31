<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tenancy;

use Atannex\Bridge\Contracts\TenantResolver;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

final class ClosureTenantResolver implements TenantResolver
{
    public function __construct(private readonly Closure $resolver) {}

    public function resolve(Request $request, ?Authenticatable $user, ?string $requested): ?Tenant
    {
        return ($this->resolver)($request, $user, $requested);
    }
}
