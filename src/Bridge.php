<?php

declare(strict_types=1);

namespace Atannex\Bridge;

use Atannex\Bridge\Authentication\PrincipalResolver;
use Atannex\Bridge\Authentication\UserPresenter;
use Atannex\Bridge\Contracts\TenantResolver;
use Atannex\Bridge\Registry\Registry;
use Atannex\Bridge\Tenancy\ClosureTenantResolver;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * The app-facing entry point (also the `Bridge` facade).
 */
final class Bridge
{
    public function __construct(
        private readonly Container $container,
        private readonly Registry $registry,
    ) {}

    /**
     * Expose an action as an operation.
     *
     *     Bridge::action('orders.create', CreateOrder::class, [
     *         'access' => Access::Authenticated,
     *         'idempotency' => Idempotency::Idempotent,
     *     ]);
     *
     * @param  class-string<Contracts\Action>  $class
     * @param  array<string, mixed>  $options  same keys as #[Operation]
     */
    public function action(string $name, string $class, array $options = []): void
    {
        $this->registry->action($name, $class, $options);
    }

    /**
     * Expose a broadcast event's name and payload type to clients.
     *
     * @param  class-string<Contracts\BroadcastEvent>  $class
     * @param  array<string, mixed>  $options  same keys as #[Event]
     */
    public function event(string $class, array $options = []): void
    {
        $this->registry->event($class, $options);
    }

    /**
     * @param  (Closure(Request, ?Authenticatable, ?string): ?Tenancy\Tenant)|class-string<TenantResolver>  $resolver
     */
    public function resolveTenantsUsing(Closure|string $resolver): void
    {
        $this->container->instance(TenantResolver::class, $resolver instanceof Closure
            ? new ClosureTenantResolver($resolver)
            : $this->container->make($resolver));
    }

    /**
     * Replace guard-based user lookup.
     *
     * @param  (Closure(Request): ?Authenticatable)|null  $resolver
     */
    public function resolveUsersUsing(?Closure $resolver): void
    {
        $this->container->make(PrincipalResolver::class)->using($resolver);
    }

    /**
     * Control what /auth/user and token login return about the user.
     *
     * @param  (Closure(Authenticatable): array<string, mixed>)|null  $presenter
     */
    public function presentUsersUsing(?Closure $presenter): void
    {
        $this->container->make(UserPresenter::class)->using($presenter);
    }

    /**
     * Decide who mobile token login lets in. Defaults to Fortify's
     * authenticateUsing() callback when set, else the guard's user provider.
     *
     * @param  (Closure(Request): ?Authenticatable)|null  $callback
     */
    public function authenticateTokensUsing(?Closure $callback): void
    {
        $this->container->make(Authentication\TokenAuthenticator::class)->using($callback);
    }

    public function registry(): Registry
    {
        return $this->registry;
    }
}
