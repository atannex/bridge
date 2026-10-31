<?php

declare(strict_types=1);

namespace Atannex\Bridge\Console;

use Atannex\Bridge\Attributes\Access;
use Atannex\Bridge\Attributes\Idempotency;
use Atannex\Bridge\Attributes\TenantMode;
use Atannex\Bridge\Contracts\Authorizes;
use Atannex\Bridge\Contracts\HasInput;
use Atannex\Bridge\Contracts\TenantResolver;
use Atannex\Bridge\Generation\TypeScriptGenerator;
use Atannex\Bridge\Registry\Registry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'bridge:doctor')]
final class DoctorCommand extends Command
{
    protected $signature = 'bridge:doctor';

    protected $description = 'Check the Bridge setup and report problems';

    /** @var list<array{string, string, string}> */
    private array $results = [];

    public function handle(Registry $registry, Router $router): int
    {
        $this->checkRegistry($registry);
        $this->checkRoutes($router);
        $this->checkAuthentication();
        $this->checkProduction($registry);
        $this->checkStores();

        $this->table(['', 'Check', 'Detail'], array_map(
            fn (array $row) => [match ($row[0]) {
                'ok' => '<fg=green>OK</>',
                'warn' => '<fg=yellow>WARN</>',
                default => '<fg=red>FAIL</>',
            }, $row[1], $row[2]],
            $this->results,
        ));

        $failures = count(array_filter($this->results, fn (array $row) => $row[0] === 'fail'));
        $warnings = count(array_filter($this->results, fn (array $row) => $row[0] === 'warn'));

        if ($failures > 0) {
            $this->components->error("{$failures} problem(s), {$warnings} warning(s).");

            return self::FAILURE;
        }

        $this->components->info("No problems found ({$warnings} warning(s)).");

        return self::SUCCESS;
    }

    private function checkRegistry(Registry $registry): void
    {
        try {
            $operations = $registry->operations();
            $registry->events();
        } catch (Throwable $e) {
            $this->problem('Operations load', $e->getMessage());

            return;
        }

        $this->ok('Operations load', count($operations).' operation(s)'.($registry->isCached() ? ', from cache' : ''));

        foreach ($operations as $name => $definition) {
            try {
                $action = $this->laravel->make($definition->class);
            } catch (Throwable $e) {
                $this->problem($name, 'Action cannot be built: '.$e->getMessage());

                continue;
            }

            if ($action instanceof HasInput && $action->input()->isNullable()) {
                $this->problem($name, 'The input object cannot be nullable.');
            }

            if ($definition->access === Access::Protected && $definition->can === null && ! $action instanceof Authorizes) {
                $this->problem($name, 'Protected, but no `can` ability and no Authorizes: every call is refused. Use access: Access::Authenticated if any signed-in user may call it.');
            }

            if ($definition->tenant !== TenantMode::None && ! $this->laravel->bound(TenantResolver::class)) {
                $this->problem($name, 'Uses tenants, but no tenant resolver is configured.');
            }

            $limiter = $definition->rateLimit ?? config('bridge.rate_limit');

            if ($limiter !== null && $limiter !== 'none' && RateLimiter::limiter($limiter) === null) {
                $this->problem($name, "Rate limiter [{$limiter}] is not defined.");
            }

            if ($definition->access === Access::Guest && $definition->idempotency !== Idempotency::Safe) {
                $this->caution($name, 'Guests can call this write operation. Make sure that is intended.');
            }
        }

        try {
            $contract = $this->laravel->make(TypeScriptGenerator::class)->generate();

            foreach ($contract->errors as $error) {
                $this->problem('TypeScript generation', $error);
            }

            foreach ($contract->warnings as $warning) {
                $this->caution('TypeScript generation', $warning);
            }

            if (! $contract->failed()) {
                $this->ok('TypeScript generation', 'Contract generates cleanly.');
            }
        } catch (Throwable $e) {
            $this->problem('TypeScript generation', $e->getMessage());
        }
    }

    private function checkRoutes(Router $router): void
    {
        if (! config('bridge.routes.enabled')) {
            $this->caution('Routes', 'Bridge routes are disabled (bridge.routes.enabled).');

            return;
        }

        $prefix = trim((string) config('bridge.routes.prefix'), '/');
        $foreign = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (($uri === $prefix || str_starts_with($uri, $prefix.'/')) && ! str_starts_with((string) $route->getName(), 'bridge.')) {
                $foreign[] = $uri;
            }
        }

        $foreign === []
            ? $this->ok('Routes', "Served under /{$prefix}.")
            : $this->caution('Routes', 'Other routes share the Bridge prefix: '.implode(', ', array_slice($foreign, 0, 5)));
    }

    private function checkAuthentication(): void
    {
        foreach (config('bridge.guards') ?? [config('auth.defaults.guard')] as $guard) {
            if (config("auth.guards.{$guard}") === null && ! ($guard === 'sanctum' && class_exists(Sanctum::class))) {
                $this->problem('Guards', "Guard [{$guard}] is not defined in config/auth.php.");
            }
        }

        if (config('bridge.auth.tokens.enabled')) {
            if (! class_exists(Sanctum::class)) {
                $this->problem('Mobile tokens', 'bridge.auth.tokens.enabled needs laravel/sanctum.');
            } else {
                $model = config('auth.providers.'.config('auth.guards.'.config('bridge.auth.tokens.guard', 'web').'.provider').'.model');

                if (is_string($model) && ! method_exists($model, 'createToken')) {
                    $this->problem('Mobile tokens', "[{$model}] needs the Laravel\\Sanctum\\HasApiTokens trait.");
                } else {
                    $this->ok('Mobile tokens', 'Enabled.');
                }
            }
        }

        if (class_exists(Sanctum::class)) {
            $paths = (array) config('cors.paths', []);
            $prefix = trim((string) config('bridge.routes.prefix'), '/');
            $covered = collect($paths)->contains(fn (string $path) => $path === '*' || str_starts_with($prefix.'/', rtrim($path, '*')));

            if (! $covered) {
                $this->caution('CORS', "config/cors.php paths do not include '{$prefix}/*'; browser clients on another origin will be blocked.");
            }
        }
    }

    private function checkProduction(Registry $registry): void
    {
        if (! $this->laravel->isProduction()) {
            return;
        }

        $registry->isCached()
            ? $this->ok('Registry cache', 'Compiled.')
            : $this->caution('Registry cache', 'Not cached; run `php artisan bridge:cache` (or `optimize`) on deploy.');

        if (! config('bridge.require_https')) {
            $this->problem('HTTPS', 'bridge.require_https is off in production.');
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $this->caution('HTTPS', 'APP_URL is not https; signed download links will be http.');
        }

        if (config('session.secure') !== true) {
            $this->caution('Cookies', 'SESSION_SECURE_COOKIE is not true; session cookies may travel over plain HTTP.');
        }
    }

    private function checkStores(): void
    {
        $store = $this->laravel['cache']->store(config('bridge.idempotency.store'))->getStore();

        $store instanceof LockProvider
            ? $this->ok('Idempotency store', class_basename($store).' supports locks.')
            : $this->problem('Idempotency store', 'The cache store cannot lock; idempotent operations will fail.');

        foreach ((array) config('bridge.files.disks', []) as $disk) {
            if (config("filesystems.disks.{$disk}") === null) {
                $this->problem('Files', "Disk [{$disk}] in bridge.files.disks is not configured.");
            }
        }
    }

    private function ok(string $check, string $detail): void
    {
        $this->results[] = ['ok', $check, $detail];
    }

    private function caution(string $check, string $detail): void
    {
        $this->results[] = ['warn', $check, $detail];
    }

    private function problem(string $check, string $detail): void
    {
        $this->results[] = ['fail', $check, $detail];
    }
}
