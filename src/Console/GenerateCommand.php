<?php

declare(strict_types=1);

namespace Atannex\Bridge\Console;

use Atannex\Bridge\Generation\TypeScriptGenerator;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'bridge:generate')]
final class GenerateCommand extends Command
{
    protected $signature = 'bridge:generate
        {--output= : File to write (default: config bridge.generate.output)}
        {--check : Fail if the file on disk is out of date; write nothing}';

    protected $description = 'Generate the TypeScript contract for Bridge operations and events';

    public function handle(TypeScriptGenerator $generator): int
    {
        $config = config('bridge.generate');
        $path = $this->option('output') ?: ($config['output'] ?? null) ?: resource_path('js/generated/bridge.ts');

        if (! str_ends_with($path, '.ts')) {
            $path = rtrim($path, '/').'/bridge.ts';
        }

        $contract = $generator->generate($config['interface'] ?? 'AppBridge', $config['module'] ?? '@atannex/bridge');

        foreach ($contract->warnings as $warning) {
            $this->components->warn($warning);
        }

        if ($contract->failed()) {
            foreach ($contract->errors as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        if ($this->option('check')) {
            if (! is_file($path) || file_get_contents($path) !== $contract->content) {
                $this->components->error("The Bridge contract at [{$path}] is out of date. Run `php artisan bridge:generate`.");

                return self::FAILURE;
            }

            $this->components->info('The Bridge contract is up to date.');

            return self::SUCCESS;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        if (is_file($path) && file_get_contents($path) === $contract->content) {
            $this->components->info("Bridge contract unchanged [{$path}].");

            return self::SUCCESS;
        }

        file_put_contents($path, $contract->content);
        $this->components->info("Bridge contract written to [{$path}].");

        return self::SUCCESS;
    }
}
