<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Feature;

use Atannex\Bridge\Bridge;
use Atannex\Bridge\Registry\Registry;
use Atannex\Bridge\Tests\Fixtures\OrderUpdated;
use Atannex\Bridge\Tests\Fixtures\Status;
use Atannex\Bridge\Tests\TestCase;

final class BroadcastTest extends TestCase
{
    public function test_events_broadcast_under_their_bridge_name_with_only_the_schema_payload(): void
    {
        $event = new OrderUpdated(7, Status::Published, secret: 'internal');

        $this->assertSame('orders.updated', $event->broadcastAs());
        $this->assertSame(['id' => 7, 'status' => 'published'], $event->broadcastWith());
    }

    public function test_discovered_operations_are_callable(): void
    {
        config(['bridge.discovery' => ['Atannex\\Bridge\\Tests\\Fixtures\\Discovered' => __DIR__.'/../Fixtures/Discovered']]);
        $this->app->forgetInstance(Registry::class);
        $this->app->forgetInstance(Bridge::class);
        \Atannex\Bridge\Facades\Bridge::clearResolvedInstances();

        $this->call_('system.ping')->assertOk()->assertJsonPath('data.pong', true);
        // Implements Action, but without #[Operation] it is not exposed.
        $this->call_('system.not_an_operation')->assertNotFound();
    }
}
