<?php

declare(strict_types=1);

namespace Atannex\Bridge\Registry;

use Atannex\Bridge\Attributes\Event;
use Atannex\Bridge\Attributes\Operation;
use Atannex\Bridge\Exceptions\DuplicateOperation;
use Atannex\Bridge\Exceptions\InvalidOperation;
use ReflectionClass;

/**
 * The operations and events the app has explicitly exposed.
 *
 * Registration only records names and classes; nothing is reflected until a
 * definition is needed. With a compiled cache, discovery never runs at all.
 * The registry holds no per-request state, so it is safe to share across
 * requests in Octane.
 */
final class Registry
{
    /** @var array<string, array{class: string, options: array<string, mixed>}> */
    private array $pendingOperations = [];

    /** @var array<string, array{class: string, options: array<string, mixed>}> */
    private array $pendingEvents = [];

    /** @var array<string, OperationDefinition>|null */
    private ?array $operations = null;

    /** @var array<string, EventDefinition>|null */
    private ?array $events = null;

    /**
     * @param  array<string, string>  $namespaces  namespace => directory
     */
    public function __construct(
        private readonly Discovery $discovery,
        private readonly array $namespaces,
        private readonly string $cachePath,
    ) {}

    /**
     * Expose an action. Options use the same keys as the #[Operation]
     * attribute and override it; with no options the attribute (if any)
     * supplies them.
     *
     * @param  array<string, mixed>  $options
     */
    public function action(string $name, string $class, array $options = []): void
    {
        if (isset($this->pendingOperations[$name]) && $this->pendingOperations[$name]['class'] !== $class) {
            throw DuplicateOperation::operation($name, $this->pendingOperations[$name]['class'], $class);
        }

        $this->pendingOperations[$name] = ['class' => $class, 'options' => $options];
        $this->operations = null;
    }

    /**
     * Register a broadcast event class carrying #[Event] (or pass options).
     *
     * @param  array<string, mixed>  $options
     */
    public function event(string $class, array $options = []): void
    {
        $this->pendingEvents[$class] = ['class' => $class, 'options' => $options];
        $this->events = null;
    }

    public function find(string $name): ?OperationDefinition
    {
        return $this->operations()[$name] ?? null;
    }

    /**
     * @return array<string, OperationDefinition> sorted by name
     */
    public function operations(): array
    {
        if ($this->operations === null) {
            $this->load();
        }

        return $this->operations;
    }

    /**
     * @return array<string, EventDefinition> sorted by name
     */
    public function events(): array
    {
        if ($this->events === null) {
            $this->load();
        }

        return $this->events;
    }

    public function eventFor(string $class): ?EventDefinition
    {
        foreach ($this->events() as $event) {
            if ($event->class === $class) {
                return $event;
            }
        }

        return null;
    }

    public function isCached(): bool
    {
        return is_file($this->cachePath);
    }

    public function cachePath(): string
    {
        return $this->cachePath;
    }

    /**
     * Compile everything (discovered and explicit) into the cache file.
     */
    public function cache(): int
    {
        $compiled = $this->compile(useCache: false);

        $export = [
            'operations' => array_map(fn (OperationDefinition $d) => $d->toArray(), $compiled['operations']),
            'events' => array_map(fn (EventDefinition $d) => $d->toArray(), $compiled['events']),
        ];

        if (! is_dir(dirname($this->cachePath))) {
            mkdir(dirname($this->cachePath), 0755, true);
        }

        $temporary = $this->cachePath.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($temporary, '<?php return '.var_export($export, true).';'.PHP_EOL);
        rename($temporary, $this->cachePath);

        $this->operations = $compiled['operations'];
        $this->events = $compiled['events'];

        return count($compiled['operations']);
    }

    public function clearCache(): void
    {
        if (is_file($this->cachePath)) {
            unlink($this->cachePath);
        }

        $this->operations = null;
        $this->events = null;
    }

    private function load(): void
    {
        $compiled = $this->compile(useCache: true);
        $this->operations = $compiled['operations'];
        $this->events = $compiled['events'];
    }

    /**
     * @return array{operations: array<string, OperationDefinition>, events: array<string, EventDefinition>}
     */
    private function compile(bool $useCache): array
    {
        $operations = [];
        $events = [];

        if ($useCache && is_file($this->cachePath)) {
            $cached = require $this->cachePath;

            foreach ($cached['operations'] as $data) {
                $operations[$data['name']] = OperationDefinition::fromArray($data);
            }

            foreach ($cached['events'] as $data) {
                $events[$data['name']] = EventDefinition::fromArray($data);
            }
        } else {
            $found = $this->discovery->discover($this->namespaces);

            foreach ($found['operations'] as $definition) {
                $this->put($operations, $definition);
            }

            foreach ($found['events'] as $definition) {
                $this->putEvent($events, $definition);
            }
        }

        // Explicit registrations not already compiled into the cache.
        foreach ($this->pendingOperations as $name => $pending) {
            if (isset($operations[$name]) && $operations[$name]->class === $pending['class'] && $useCache && is_file($this->cachePath)) {
                continue;
            }

            $definition = $this->definitionFor($name, $pending['class'], $pending['options']);

            if (isset($operations[$name]) && $operations[$name]->class !== $definition->class) {
                throw DuplicateOperation::operation($name, $operations[$name]->class, $definition->class);
            }

            $operations[$name] = $definition;
        }

        foreach ($this->pendingEvents as $class => $pending) {
            $definition = $this->eventDefinitionFor($class, $pending['options']);

            if (isset($events[$definition->name]) && $events[$definition->name]->class === $class) {
                continue;
            }

            $this->putEvent($events, $definition);
        }

        ksort($operations);
        ksort($events);

        return ['operations' => $operations, 'events' => $events];
    }

    /**
     * @param  array<string, OperationDefinition>  $operations
     */
    private function put(array &$operations, OperationDefinition $definition): void
    {
        if (isset($operations[$definition->name])) {
            throw DuplicateOperation::operation($definition->name, $operations[$definition->name]->class, $definition->class);
        }

        $operations[$definition->name] = $definition;
    }

    /**
     * @param  array<string, EventDefinition>  $events
     */
    private function putEvent(array &$events, EventDefinition $definition): void
    {
        if (isset($events[$definition->name])) {
            throw DuplicateOperation::event($definition->name, $events[$definition->name]->class, $definition->class);
        }

        $events[$definition->name] = $definition;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function definitionFor(string $name, string $class, array $options): OperationDefinition
    {
        if (! class_exists($class)) {
            throw InvalidOperation::notAnAction($name, $class);
        }

        $attribute = (new ReflectionClass($class))->getAttributes(Operation::class)[0] ?? null;
        $base = $attribute !== null ? get_object_vars($attribute->newInstance()) : [];

        return OperationDefinition::fromArray(['class' => $class] + array_diff_key($options, ['name' => true]) + ['name' => $name] + $base);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function eventDefinitionFor(string $class, array $options): EventDefinition
    {
        $attribute = class_exists($class) ? ((new ReflectionClass($class))->getAttributes(Event::class)[0] ?? null) : null;
        $base = $attribute !== null ? get_object_vars($attribute->newInstance()) : [];

        if (! isset(($options + $base)['name'])) {
            throw InvalidOperation::eventWithoutName($class);
        }

        return EventDefinition::fromArray(['class' => $class] + $options + $base);
    }
}
