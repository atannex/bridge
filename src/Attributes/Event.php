<?php

declare(strict_types=1);

namespace Atannex\Bridge\Attributes;

use Attribute;

/**
 * Names a broadcast event for clients and the generated contract.
 *
 *     #[Event('orders.updated', channel: 'private')]
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Event
{
    public function __construct(
        public string $name,
        public string $channel = 'private',
        public ?string $description = null,
    ) {
        if (! in_array($channel, ['public', 'private', 'presence'], true)) {
            throw new \InvalidArgumentException("Event channel must be public, private or presence, [{$channel}] given.");
        }
    }
}
