<?php

declare(strict_types=1);

namespace Atannex\Bridge\Console;

use Atannex\Bridge\Registry\Registry;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'bridge:clear')]
final class ClearCommand extends Command
{
    protected $signature = 'bridge:clear';

    protected $description = 'Remove the compiled Bridge metadata';

    public function handle(Registry $registry): int
    {
        $registry->clearCache();
        $this->components->info('Bridge cache cleared.');

        return self::SUCCESS;
    }
}
