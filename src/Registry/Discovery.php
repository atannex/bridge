<?php

declare(strict_types=1);

namespace Atannex\Bridge\Registry;

use Atannex\Bridge\Attributes\Event;
use Atannex\Bridge\Attributes\Operation;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Finds #[Operation] and #[Event] classes in the namespaces the app lists in
 * config('bridge.discovery'). Never scans anything else, and only runs when
 * there is no compiled cache (`php artisan bridge:cache`).
 */
final class Discovery
{
    /**
     * @param  array<string, string>  $namespaces  namespace prefix => directory
     * @return array{operations: list<OperationDefinition>, events: list<EventDefinition>}
     */
    public function discover(array $namespaces): array
    {
        $operations = [];
        $events = [];

        foreach ($namespaces as $namespace => $directory) {
            foreach ($this->classesIn(rtrim($namespace, '\\'), $directory) as $class) {
                $reflection = new ReflectionClass($class);

                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    continue;
                }

                foreach ($reflection->getAttributes(Operation::class) as $attribute) {
                    $operations[] = OperationDefinition::fromAttribute($attribute->newInstance(), $class);
                }

                foreach ($reflection->getAttributes(Event::class) as $attribute) {
                    $events[] = EventDefinition::fromAttribute($attribute->newInstance(), $class);
                }
            }
        }

        return ['operations' => $operations, 'events' => $events];
    }

    /**
     * @return list<class-string>
     */
    private function classesIn(string $namespace, string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $root = realpath($directory);
        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = $namespace.'\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }
}
