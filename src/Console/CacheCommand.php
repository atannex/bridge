<?php

declare(strict_types=1);

namespace Atannex\Bridge\Console;

use Atannex\Bridge\Registry\Registry;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'bridge:cache')]
final class CacheCommand extends Command
{
    protected $signature = 'bridge:cache';

    protected $description = 'Compile Bridge operation metadata so no discovery runs on requests';

    public function handle(Registry $registry): int
    {
        $registry->clearCache();
        $count = $registry->cache();

        $this->components->info("Bridge cached {$count} operation(s) to [{$registry->cachePath()}].");

        return self::SUCCESS;
    }
}
