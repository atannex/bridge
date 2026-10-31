<?php

declare(strict_types=1);

namespace Atannex\Bridge\Console;

use Illuminate\Console\Command;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'bridge:install')]
final class InstallCommand extends Command
{
    protected $signature = 'bridge:install {--force : Overwrite config/bridge.php if it exists}';

    protected $description = 'Publish the Bridge config and explain the next steps';

    public function handle(): int
    {
        $target = config_path('bridge.php');

        if (is_file($target) && ! $this->option('force')) {
            if (! $this->input->isInteractive() || ! $this->components->confirm('config/bridge.php already exists. Overwrite it?', false)) {
                $this->components->info('Kept the existing config/bridge.php.');
            } else {
                $this->publish(true);
            }
        } else {
            $this->publish((bool) $this->option('force'));
        }

        $sanctum = class_exists(Sanctum::class);

        $this->newLine();
        $this->components->bulletList([
            'Register operations in a service provider: Bridge::action(\'products.list\', ListProducts::class)',
            'or list a namespace under bridge.discovery and mark actions with #[Operation].',
            'Generate the TypeScript contract: php artisan bridge:generate',
            'Install the client: npm install @atannex/bridge',
            $sanctum
                ? 'Sanctum found: set bridge.guards to [\'sanctum\'] for SPA sessions and bearer tokens.'
                : 'For SPA sessions or mobile tokens, install laravel/sanctum (optional).',
            'Check the setup any time: php artisan bridge:doctor',
        ]);

        return self::SUCCESS;
    }

    private function publish(bool $force): void
    {
        $this->call('vendor:publish', ['--tag' => 'bridge-config', '--force' => $force]);
    }
}
