<?php

declare(strict_types=1);

namespace Atannex\Bridge\Support;

use Atannex\Bridge\Attributes\Event;
use Atannex\Bridge\Registry\Registry;
use Atannex\Bridge\Serialization\Serializer;
use ReflectionClass;

/**
 * For broadcast events implementing BroadcastEvent: broadcasts under the
 * #[Event] name and sends exactly the payload schema, nothing else.
 *
 *     #[Event('orders.updated', channel: 'private')]
 *     final class OrderUpdated implements ShouldBroadcast, BroadcastEvent
 *     {
 *         use BroadcastsThroughBridge;
 *         ...
 *     }
 */
trait BroadcastsThroughBridge
{
    public function broadcastAs(): string
    {
        $attribute = (new ReflectionClass(static::class))->getAttributes(Event::class)[0] ?? null;

        if ($attribute !== null) {
            return $attribute->newInstance()->name;
        }

        return app(Registry::class)->eventFor(static::class)->name
            ?? throw new \LogicException(static::class.' has no Bridge event name.');
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $data = (new Serializer(config('bridge.serialization.timezone', 'UTC')))
            ->serialize(static::payloadSchema(), $this->payload());

        return is_array($data) ? $data : ['value' => $data];
    }
}
