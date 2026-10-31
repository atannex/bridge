<?php

declare(strict_types=1);

namespace Atannex\Bridge;

use Atannex\Bridge\Exceptions\BridgeException;
use Atannex\Bridge\Registry\OperationDefinition;
use Atannex\Bridge\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Trusted, per-call facts for an action.
 *
 * Built fresh for every call and passed as an argument, never stored in the
 * container, so nothing about one request can leak into the next (queues,
 * tests, Octane). Everything here comes from the server; what the client
 * claims about itself is kept apart in $client and must not be trusted.
 */
final readonly class Context
{
    public function __construct(
        public string $requestId,
        public string $correlationId,
        public OperationDefinition $operation,
        public ?Authenticatable $user,
        public ?string $guard,
        public ?Tenant $tenant,
        public string $locale,
        public ClientMetadata $client,
        public CarbonImmutable $receivedAt,
        private Gate $gate,
    ) {}

    public function user(): ?Authenticatable
    {
        return $this->user;
    }

    /** The user, or a 401 if there is none. */
    public function requireUser(): Authenticatable
    {
        return $this->user ?? throw BridgeException::unauthenticated();
    }

    /** The tenant, or a 400 TENANT_REQUIRED if there is none. */
    public function tenant(): Tenant
    {
        return $this->tenant ?? throw BridgeException::tenantRequired();
    }

    /**
     * Whether the current user passes a Gate ability or policy method.
     */
    public function can(string $ability, mixed $arguments = []): bool
    {
        return $this->gate->forUser($this->user)->allows($ability, $arguments);
    }

    /**
     * Check a Gate ability or policy method for the current user, typically on
     * a record the action just loaded. Refuses with 403, or 404 when the policy
     * answered Response::denyAsNotFound().
     */
    public function authorize(string $ability, mixed $arguments = []): void
    {
        $this->gate->forUser($this->user)->authorize($ability, $arguments);
    }
}
