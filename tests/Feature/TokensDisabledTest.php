<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Feature;

use Atannex\Bridge\Tests\TestCase;

final class TokensDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('bridge.auth.tokens.enabled', false);
    }

    public function test_token_endpoints_do_not_exist_unless_enabled(): void
    {
        $this->assertFalse($this->app['router']->has('bridge.auth.token'));
        $this->assertFalse($this->app['router']->has('bridge.auth.refresh'));

        $this->postJson('/bridge/v1/auth/token', ['email' => 'a@b.c', 'password' => 'x', 'device_name' => 'x'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
