<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Fixtures;

use Atannex\Bridge\Attributes\Event;
use Atannex\Bridge\Contracts\BroadcastEvent;
use Atannex\Bridge\Schema\Schema;
use Atannex\Bridge\Schema\Type;
use Atannex\Bridge\Support\BroadcastsThroughBridge;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

#[Event('orders.updated', channel: 'private', description: 'An order changed status.')]
final class OrderUpdated implements BroadcastEvent, ShouldBroadcast
{
    use BroadcastsThroughBridge;

    public function __construct(public int $id, public Status $status, public string $secret = 'internal') {}

    public static function payloadSchema(): Type
    {
        return Schema::object(['id' => Schema::integer(), 'status' => Schema::enum(Status::class)]);
    }

    public function payload(): mixed
    {
        return $this;
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.'.$this->id)];
    }
}
