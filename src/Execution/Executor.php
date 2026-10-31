<?php

declare(strict_types=1);

namespace Atannex\Bridge\Execution;

use Atannex\Bridge\Attributes\Access;
use Atannex\Bridge\Attributes\Idempotency;
use Atannex\Bridge\Attributes\TenantMode;
use Atannex\Bridge\Authentication\PrincipalResolver;
use Atannex\Bridge\ClientMetadata;
use Atannex\Bridge\Context;
use Atannex\Bridge\Contracts\Action;
use Atannex\Bridge\Contracts\Authorizes;
use Atannex\Bridge\Contracts\HasInput;
use Atannex\Bridge\Contracts\HasOutput;
use Atannex\Bridge\Contracts\TenantResolver;
use Atannex\Bridge\Events\OperationHandled;
use Atannex\Bridge\Events\OperationStarting;
use Atannex\Bridge\Exceptions\BridgeException;
use Atannex\Bridge\Exceptions\InvalidOperation;
use Atannex\Bridge\Exceptions\OutputContractViolation;
use Atannex\Bridge\Files\SignedFiles;
use Atannex\Bridge\Http\Envelope;
use Atannex\Bridge\Input;
use Atannex\Bridge\Protocol;
use Atannex\Bridge\Registry\OperationDefinition;
use Atannex\Bridge\Registry\Registry;
use Atannex\Bridge\Serialization\Serializer;
use Atannex\Bridge\Support\RequestIds;
use Atannex\Bridge\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Routing\Router;
use Throwable;

/**
 * Runs one operation call through the fixed pipeline:
 *
 *  1. protocol version and transport checks
 *  2. resolve the registered operation (unknown → 404)
 *  3. operation middleware
 *  4. authenticated principal (Laravel guards)        → 401
 *  5. trusted tenant context                           → 400 / 403
 *  6. body size, shape and nesting limits              → 400 / 413 / 415
 *  7. rate limit                                       → 429
 *  8. function-level authorization (access, `can`)     → 403
 *  9. idempotency (replay / conflict)                  → 409
 * 10. validation against the input schema              → 422
 * 11. record-level authorization (Authorizes)          → 403 / 404
 * 12. the action
 * 13. output through the schema allowlist
 *
 * Steps 4, 8, 10 and 11 cannot be skipped by configuration or middleware:
 * middleware wraps the pipeline but cannot reach past it to call the action.
 *
 * The executor keeps no state between calls.
 */
final class Executor
{
    /**
     * @param  array<string, mixed>  $config  the `bridge` config array
     */
    public function __construct(
        private readonly Container $container,
        private readonly Registry $registry,
        private readonly PrincipalResolver $principals,
        private readonly RequestReader $reader,
        private readonly RateLimits $rateLimits,
        private readonly ErrorMapper $errors,
        private readonly ValidatorFactory $validator,
        private readonly Gate $gate,
        private readonly Dispatcher $events,
        private readonly array $config,
    ) {}

    public function handle(Request $request, string $operation): JsonResponse
    {
        $started = hrtime(true);
        [$requestId, $correlationId] = RequestIds::for($request);
        $state = new CallState;

        try {
            $this->checkTransport($request);

            $definition = strlen($operation) <= 120 ? $this->registry->find($operation) : null;

            if ($definition === null) {
                throw BridgeException::notFound();
            }

            $state->definition = $definition;
            $this->events->dispatch(new OperationStarting($definition->name, $requestId, $correlationId));

            $response = $this->throughMiddleware($request, $definition, fn (Request $request) => $this->run($request, $definition, $state));
        } catch (Throwable $e) {
            $response = $this->failure($request, $e, $state);
        } finally {
            $state->restoreLocale();
        }

        $this->events->dispatch(new OperationHandled(
            operation: $state->definition?->name,
            requestId: $requestId,
            correlationId: $correlationId,
            status: $response->getStatusCode(),
            errorCode: $response->getStatusCode() >= 400 ? ($response->getData(true)['error']['code'] ?? null) : null,
            durationMs: round((hrtime(true) - $started) / 1e6, 3),
            userId: $state->user?->getAuthIdentifier(),
            tenant: $state->tenant?->key,
            replayed: $state->replayed,
        ));

        return $response;
    }

    private function checkTransport(Request $request): void
    {
        $version = $request->headers->get(Protocol::VERSION_HEADER);

        if ($version !== null && ! in_array($version, Protocol::SUPPORTED_VERSIONS, true)) {
            throw BridgeException::unsupportedVersion(Protocol::SUPPORTED_VERSIONS);
        }

        if (($this->config['require_https'] ?? false) && ! $request->secure()) {
            throw BridgeException::httpsRequired();
        }
    }

    /**
     * @param  callable(Request): JsonResponse  $then
     */
    private function throughMiddleware(Request $request, OperationDefinition $definition, callable $then): JsonResponse
    {
        if ($definition->middleware === []) {
            return $then($request);
        }

        $router = $this->container->make(Router::class);
        $middleware = collect($definition->middleware)
            ->map(fn (string $name) => (array) MiddlewareNameResolver::resolve($name, $router->getMiddleware(), $router->getMiddlewareGroups()))
            ->flatten()
            ->all();

        $response = (new Pipeline($this->container))->send($request)->through($middleware)->then($then);

        if (! $response instanceof JsonResponse) {
            // A middleware answered on its own (a redirect, a view). Clients
            // only understand the protocol, so refuse rather than pass it on.
            throw BridgeException::forbidden();
        }

        return $response;
    }

    private function run(Request $request, OperationDefinition $definition, CallState $state): JsonResponse
    {
        // 4. Principal.
        [$state->user, $guard] = $this->principals->resolve($request);
        $request->setUserResolver(fn () => $state->user);

        if ($definition->requiresUser() && $state->user === null) {
            throw BridgeException::unauthenticated();
        }

        // 5. Tenant.
        $requestedTenant = $this->requestedTenant($request);
        $state->tenant = $this->tenant($request, $definition, $state->user, $requestedTenant);

        // 6. Body.
        $raw = $this->reader->read($request);

        // 7. Rate limit.
        $limiter = $definition->rateLimit ?? ($this->config['rate_limit'] ?? null);

        if ($limiter !== null && $limiter !== 'none') {
            $this->rateLimits->hit($limiter, $request);
        }

        // 8. Function-level authorization.
        $action = $this->container->make($definition->class);
        $this->authorizeOperation($definition, $action, $state->user);

        // 9. Idempotency.
        $claim = null;
        $key = RequestIds::accept($request->headers->get(Protocol::IDEMPOTENCY_HEADER));

        if ($definition->idempotency === Idempotency::Idempotent && $key !== null) {
            $begun = $this->container->make(IdempotencyStore::class)->begin(
                $definition->name,
                $this->idempotencyScope($request, $state->user),
                $state->tenant !== null ? (string) $state->tenant->key : null,
                $key,
                $raw,
            );

            if ($begun['replay'] !== null) {
                $state->replayed = true;
                $meta = $begun['replay']['payload']['meta'] ?? [];
                $payload = $begun['replay']['payload'];
                unset($payload['meta']);

                return Envelope::respond($request, $payload, $begun['replay']['status'], $meta + ['idempotent_replay' => true]);
            }

            $state->claim = $claim = $begun['claim'];
        }

        // 10. Validation.
        $input = $this->validate($definition, $action, $raw);

        // Locale for messages and the action, restored after the call.
        $state->setLocale($this->locale($request));

        $context = new Context(
            requestId: $request->attributes->get('bridge.request_id'),
            correlationId: $request->attributes->get('bridge.correlation_id'),
            operation: $definition,
            user: $state->user,
            guard: $guard,
            tenant: $state->tenant,
            locale: app()->getLocale(),
            client: new ClientMetadata(
                client: $this->clientName($request),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
                requestedTenant: $requestedTenant,
            ),
            receivedAt: CarbonImmutable::now(),
            gate: $this->gate,
        );

        // 11. Record-level authorization.
        if ($action instanceof Authorizes) {
            $verdict = $action->authorize($input, $context);

            if ($verdict instanceof AccessResponse) {
                $verdict->authorize();
            } elseif ($verdict !== true) {
                throw BridgeException::forbidden();
            }
        }

        // 12. The action.
        $result = $action->handle($input, $context);

        // 13. Output.
        $serializer = new Serializer(
            $this->config['serialization']['timezone'] ?? 'UTC',
            $this->container->make(SignedFiles::class),
            $state->user,
        );

        try {
            $data = $serializer->serialize($action instanceof HasOutput ? $action->output() : null, $result);
        } catch (OutputContractViolation $e) {
            throw $e->withOperation($definition->name);
        }

        $meta = $this->successMeta($definition);
        $response = Envelope::success($request, $data, $meta, $definition->deprecated !== null ? ['Deprecation' => 'true'] : []);

        if ($claim !== null) {
            $state->claim = null;
            $this->container->make(IdempotencyStore::class)->complete($claim, 200, ['data' => $data, 'meta' => $meta]);
        }

        return $response;
    }

    private function failure(Request $request, Throwable $e, CallState $state): JsonResponse
    {
        [$error, $report] = $this->errors->map($e);

        if ($report) {
            report($e);
        }

        $meta = $state->definition !== null ? ['operation' => $state->definition->name] : [];

        $response = Envelope::error($request, $error, $meta);

        if ($state->claim !== null) {
            $payload = $response->getData(true);
            unset($payload['meta']['request_id'], $payload['meta']['version']);
            $this->container->make(IdempotencyStore::class)->complete($state->claim, $error->status, $payload);
            $state->claim = null;
        }

        return $response;
    }

    /**
     * Deny by default: a Protected operation with nothing that could grant
     * access refuses every call.
     */
    private function authorizeOperation(OperationDefinition $definition, Action $action, ?Authenticatable $user): void
    {
        if ($definition->can !== null) {
            $this->gate->forUser($user)->authorize($definition->can, $definition->on !== null ? [$definition->on] : []);

            return;
        }

        if ($definition->access === Access::Protected && ! $action instanceof Authorizes) {
            throw BridgeException::forbidden();
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function validate(OperationDefinition $definition, Action $action, array $raw): Input
    {
        if (! $action instanceof HasInput) {
            return new Input;
        }

        $schema = $action->input();

        $rules = $schema->rootRules($raw);
        $this->validator->make($raw, $rules)->validate();

        return new Input($schema->hydrate($raw) ?? []);
    }

    private function tenant(Request $request, OperationDefinition $definition, ?Authenticatable $user, ?string $requested): ?Tenant
    {
        if ($definition->tenant === TenantMode::None) {
            return null;
        }

        if (! $this->container->bound(TenantResolver::class)) {
            throw InvalidOperation::noTenantResolver($definition->name);
        }

        $tenant = $this->container->make(TenantResolver::class)->resolve($request, $user, $requested);

        // The resolver may only answer with the tenant that was asked for.
        if ($requested !== null && ($tenant === null || (string) $tenant->key !== $requested)) {
            throw BridgeException::tenantForbidden();
        }

        if ($tenant === null && $definition->tenant === TenantMode::Required) {
            throw BridgeException::tenantRequired();
        }

        return $tenant;
    }

    /**
     * Who an idempotency key belongs to. Guests have no account, so their keys
     * are scoped to their session, or failing that their IP: one guest can
     * never replay another guest's stored response by reusing a key.
     */
    private function idempotencyScope(Request $request, ?Authenticatable $user): string
    {
        if ($user !== null) {
            return 'user:'.$user::class.':'.$user->getAuthIdentifier();
        }

        return $request->hasSession() && $request->session()->isStarted()
            ? 'session:'.hash('sha256', $request->session()->getId())
            : 'ip:'.$request->ip();
    }

    private function requestedTenant(Request $request): ?string
    {
        $value = $request->headers->get($this->config['tenancy']['header'] ?? Protocol::TENANT_HEADER);

        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $value) !== 1) {
            throw BridgeException::tenantForbidden();
        }

        return $value;
    }

    private function locale(Request $request): ?string
    {
        $locales = $this->config['locales'] ?? null;

        if (! is_array($locales) || $locales === [] || ! $request->headers->has('Accept-Language')) {
            return null;
        }

        return $request->getPreferredLanguage($locales);
    }

    private function clientName(Request $request): ?string
    {
        $client = $request->headers->get(Protocol::CLIENT_HEADER);

        return $client !== null && preg_match('/^[\w.\/@ -]{1,64}$/', $client) === 1 ? $client : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function successMeta(OperationDefinition $definition): array
    {
        $meta = ['operation' => $definition->name];

        if ($definition->deprecated !== null) {
            $meta['deprecated'] = ['message' => $definition->deprecated];
        }

        return $meta;
    }
}
