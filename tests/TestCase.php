<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests;

use Atannex\Bridge\BridgeServiceProvider;
use Atannex\Bridge\Facades\Bridge;
use Atannex\Bridge\Tests\Fixtures\FixtureOperations;
use Atannex\Bridge\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\FortifyServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            FortifyServiceProvider::class,
            BridgeServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return ['Bridge' => Bridge::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('bridge.guards', ['sanctum']);
        $app['config']->set('bridge.auth.tokens.enabled', true);
        $app['config']->set('bridge.cache_path', sys_get_temp_dir().'/bridge-test-'.getmypid().'.php');
        $app['config']->set('fortify.features', [Features::twoFactorAuthentication(['confirm' => true])]);
        $app['config']->set('filesystems.disks.local.root', sys_get_temp_dir().'/bridge-test-disk-'.getmypid());
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('secret_note')->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->string('internal_code')->nullable();
            $table->timestamps();
        });
    }

    protected function defineRoutes($router): void
    {
        //
    }

    protected function setUp(): void
    {
        parent::setUp();

        FixtureOperations::register();
    }

    protected function tearDown(): void
    {
        @unlink(sys_get_temp_dir().'/bridge-test-'.getmypid().'.php');

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $headers
     */
    protected function call_(string $operation, array $input = [], array $headers = []): TestResponse
    {
        return $this->postJson('/bridge/v1/call/'.$operation, ['input' => (object) $input], $headers);
    }

    protected function user(array $attributes = []): User
    {
        static $n = 0;
        $n++;

        return User::query()->create($attributes + [
            'name' => "User {$n}",
            'email' => "user{$n}@example.com",
            'password' => bcrypt('password'),
            'secret_note' => 'do not leak',
        ]);
    }
}
