<?php

declare(strict_types=1);

namespace Atannex\Bridge;

use Atannex\Bridge\Authentication\FortifyTwoFactorVerifier;
use Atannex\Bridge\Authentication\NoTwoFactor;
use Atannex\Bridge\Authentication\PrincipalResolver;
use Atannex\Bridge\Authentication\UserPresenter;
use Atannex\Bridge\Contracts\TenantResolver;
use Atannex\Bridge\Contracts\TwoFactorVerifier;
use Atannex\Bridge\Events\OperationHandled;
use Atannex\Bridge\Execution\ErrorMapper;
use Atannex\Bridge\Execution\Executor;
use Atannex\Bridge\Execution\IdempotencyStore;
use Atannex\Bridge\Execution\RequestReader;
use Atannex\Bridge\Files\SignedFiles;
use Atannex\Bridge\Generation\TypeScriptGenerator;
use Atannex\Bridge\Http\Controllers\TokenController;
use Atannex\Bridge\Http\Envelope;
use Atannex\Bridge\Registry\Discovery;
use Atannex\Bridge\Registry\Registry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;
use Throwable;

final class BridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bridge.php', 'bridge');

        $this->app->singleton(Registry::class, fn (Application $app) => new Registry(
            new Discovery,
            $app['config']->get('bridge.discovery', []),
            $app['config']->get('bridge.cache_path') ?? $app->bootstrapPath('cache/bridge.php'),
        ));

        $this->app->singleton(Bridge::class);

        $this->app->singleton(PrincipalResolver::class, fn (Application $app) => new PrincipalResolver(
            $app['auth'],
            $app['config']->get('bridge.guards'),
        ));

        $this->app->singleton(Authentication\TokenAuthenticator::class);

        $this->app->singleton(UserPresenter::class, fn (Application $app) => new UserPresenter(
            $app['config']->get('bridge.auth.user_fields', ['id']),
        ));

        $this->app->singleton(TwoFactorVerifier::class, fn (Application $app) => class_exists(Fortify::class)
            ? $app->make(FortifyTwoFactorVerifier::class)
            : new NoTwoFactor);

        $this->app->bind(RequestReader::class, fn (Application $app) => new RequestReader(
            (int) $app['config']->get('bridge.limits.json_bytes'),
            (int) $app['config']->get('bridge.limits.multipart_bytes'),
            (int) $app['config']->get('bridge.limits.max_files'),
        ));

        $this->app->bind(ErrorMapper::class);

        $this->app->bind(IdempotencyStore::class, fn (Application $app) => new IdempotencyStore(
            $app['cache']->store($app['config']->get('bridge.idempotency.store')),
            (int) $app['config']->get('bridge.idempotency.ttl'),
            (int) $app['config']->get('bridge.idempotency.lock_seconds', 300),
        ));

        $this->app->bind(SignedFiles::class, fn (Application $app) => new SignedFiles(
            $app['url'],
            $app['encrypter'],
            $app['config']->get('bridge.files.disks', []),
            (int) $app['config']->get('bridge.files.ttl'),
        ));

        $this->app->bind(Executor::class, fn (Application $app) => new Executor(
            $app,
            $app->make(Registry::class),
            $app->make(PrincipalResolver::class),
            $app->make(RequestReader::class),
            $app->make(Execution\RateLimits::class),
            $app->make(ErrorMapper::class),
            $app['validator'],
            $app->make(Gate::class),
            $app['events'],
            $app['config']->get('bridge'),
        ));

        $this->app->bind(TokenController::class, fn (Application $app) => new TokenController(
            $app['auth'],
            $app->make(\Illuminate\Cache\RateLimiter::class),
            $app['cache']->store(),
            $app->make(TwoFactorVerifier::class),
            $app->make(UserPresenter::class),
            $app->make(Authentication\TokenAuthenticator::class),
            $app['config']->get('bridge.auth'),
        ));

        $this->app->bind(TypeScriptGenerator::class);

        if (is_string($resolver = $this->app['config']->get('bridge.tenancy.resolver'))) {
            $this->app->singleton(TenantResolver::class, $resolver);
        }
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/bridge.php' => config_path('bridge.php')], 'bridge-config');

        if ($this->app['config']->get('bridge.routes.enabled') && ! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/bridge.php');
        }

        if (RateLimiter::limiter('bridge') === null) {
            RateLimiter::for('bridge', fn (Request $request) => Limit::perMinute(120)->by(
                $request->user()?->getAuthIdentifier() !== null ? 'user:'.$request->user()->getAuthIdentifier() : 'ip:'.$request->ip()
            ));
        }

        $this->renderErrorsOnBridgeRoutes();

        if (is_string($channel = $this->app['config']->get('bridge.log_channel')) && $channel !== '') {
            Event::listen(OperationHandled::class, function (OperationHandled $event) use ($channel): void {
                Log::channel($channel)->log($event->status >= 500 ? 'error' : 'info', 'bridge.call', $event->toLogContext());
            });
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\InstallCommand::class,
                Console\GenerateCommand::class,
                Console\ListCommand::class,
                Console\CacheCommand::class,
                Console\ClearCommand::class,
                Console\DoctorCommand::class,
            ]);

            $this->optimizes(optimize: 'bridge:cache', clear: 'bridge:clear', key: 'bridge');
        }
    }

    /**
     * Errors raised before Bridge's own code runs (CSRF, maintenance mode,
     * unknown routes under the prefix, signature checks, request validation
     * in the auth endpoints) still answer in protocol form.
     */
    private function renderErrorsOnBridgeRoutes(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! method_exists($handler, 'renderable')) {
                return;
            }

            $handler->renderable(function (Throwable $e, Request $request) {
                $prefix = trim((string) config('bridge.routes.prefix'), '/');

                if (! $request->is($prefix, $prefix.'/*')) {
                    return null;
                }

                [$error] = $this->app->make(ErrorMapper::class)->map($e);

                return Envelope::error($request, $error);
            });
        });
    }
}
