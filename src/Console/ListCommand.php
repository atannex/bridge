<?php

declare(strict_types=1);

namespace Atannex\Bridge\Console;

use Atannex\Bridge\Attributes\Access;
use Atannex\Bridge\Contracts\Authorizes;
use Atannex\Bridge\Registry\Registry;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'bridge:list')]
final class ListCommand extends Command
{
    protected $signature = 'bridge:list {--json : Print as JSON}';

    protected $description = 'List the operations and events Bridge exposes';

    public function handle(Registry $registry): int
    {
        $rows = [];

        foreach ($registry->operations() as $definition) {
            $authorization = match (true) {
                $definition->access === Access::Guest => 'guest',
                $definition->can !== null => 'can:'.$definition->can.($definition->on ? ' on '.class_basename($definition->on) : ''),
                is_subclass_of($definition->class, Authorizes::class) => 'authorize()',
                $definition->access === Access::Authenticated => 'any user',
                default => 'DENIED (nothing grants access)',
            };

            $rows[] = [
                'operation' => $definition->name,
                'action' => $definition->class,
                'access' => $authorization,
                'idempotency' => $definition->idempotency->value,
                'tenant' => $definition->tenant->value,
                'rate_limit' => $definition->rateLimit ?? config('bridge.rate_limit') ?? 'none',
                'version' => $definition->version,
                'deprecated' => $definition->deprecated,
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode(['operations' => $rows, 'events' => array_map(fn ($e) => $e->toArray(), array_values($registry->events()))], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->components->info('No Bridge operations are registered.');
        } else {
            $this->table(['Operation', 'Action', 'Access', 'Idempotency', 'Tenant', 'Rate limit', 'Version'], array_map(
                fn (array $row) => [
                    $row['operation'].($row['deprecated'] !== null ? ' (deprecated)' : ''),
                    $row['action'], $row['access'], $row['idempotency'], $row['tenant'], $row['rate_limit'], $row['version'],
                ],
                $rows,
            ));
        }

        if ($registry->events() !== []) {
            $this->table(['Event', 'Class', 'Channel'], array_map(
                fn ($event) => [$event->name, $event->class, $event->channel],
                array_values($registry->events()),
            ));
        }

        return self::SUCCESS;
    }
}
