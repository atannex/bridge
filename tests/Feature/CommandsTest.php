<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Feature;

use Atannex\Bridge\Registry\Registry;
use Atannex\Bridge\Tests\TestCase;

final class CommandsTest extends TestCase
{
    public function test_list_shows_operations_and_who_may_call_them(): void
    {
        $this->artisan('bridge:list')
            ->expectsOutputToContain('users.show')
            ->expectsOutputToContain('can:view-admin-stats')
            ->expectsOutputToContain('DENIED (nothing grants access)')
            ->expectsOutputToContain('orders.updated')
            ->assertSuccessful();
    }

    public function test_list_as_json(): void
    {
        $this->artisan('bridge:list', ['--json' => true])->expectsOutputToContain('"operation": "test.echo"')->assertSuccessful();
    }

    public function test_cache_and_clear(): void
    {
        $registry = app(Registry::class);

        $this->artisan('bridge:cache')->expectsOutputToContain('Bridge cached 16 operation(s)')->assertSuccessful();
        $this->assertTrue($registry->isCached());
        $this->assertIsArray(require $registry->cachePath());

        $this->artisan('bridge:clear')->assertSuccessful();
        $this->assertFalse($registry->isCached());
    }

    public function test_doctor_reports_the_deny_by_default_operation(): void
    {
        $this->artisan('bridge:doctor')
            ->expectsOutputToContain('every call is refused')
            ->assertFailed();
    }

    public function test_install_publishes_config_without_overwriting(): void
    {
        $target = config_path('bridge.php');
        @unlink($target);

        $this->artisan('bridge:install')->assertSuccessful();
        $this->assertFileExists($target);

        file_put_contents($target, "<?php return ['mine' => true];");
        $this->artisan('bridge:install', ['--no-interaction' => true])->expectsOutputToContain('Kept the existing')->assertSuccessful();
        $this->assertStringContainsString('mine', file_get_contents($target));

        unlink($target);
    }

    public function test_routes_can_be_cached(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'bridge.')) {
                $this->assertIsString($route->getAction('uses'), "Route [{$route->getName()}] uses a closure and cannot be cached.");
                $route->prepareForSerialization();
            }
        }

        $this->assertTrue(app('router')->has('bridge.call'));
    }
}
