<?php

declare(strict_types=1);

namespace Atannex\Bridge\Registry;

use Atannex\Bridge\Attributes\Event;
use Atannex\Bridge\Contracts\BroadcastEvent;
use Atannex\Bridge\Exceptions\InvalidOperation;

final readonly class EventDefinition
{
    /**
     * @param  class-string<BroadcastEvent>  $class
     */
    public function __construct(
        public string $name,
        public string $class,
        public string $channel = 'private',
        public ?string $description = null,
    ) {
        if (preg_match(OperationDefinition::NAME_PATTERN, $name) !== 1) {
            throw InvalidOperation::badName($name);
        }

        if (! is_subclass_of($class, BroadcastEvent::class)) {
            throw InvalidOperation::notAnEvent($name, $class);
        }
    }

    public static function fromAttribute(Event $event, string $class): self
    {
        return new self($event->name, $class, $event->channel, $event->description);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['class'], $data['channel'] ?? 'private', $data['description'] ?? null);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->name, 'class' => $this->class, 'channel' => $this->channel, 'description' => $this->description];
    }
}
