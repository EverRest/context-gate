<?php

declare(strict_types=1);

namespace ContextGate\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class InstallCommand extends Command
{
    protected $signature = 'gate:install {--force : Overwrite an existing config/context-gate.php}';

    protected $description = 'Publish config/context-gate.php with every tool disabled';

    public function handle(Filesystem $files): int
    {
        $target = config_path('context-gate.php');

        if ($files->exists($target) && ! $this->option('force')) {
            $this->error('config/context-gate.php already exists. Use --force to overwrite it.');

            return self::FAILURE;
        }

        $files->copy(__DIR__.'/../../config/context-gate.php', $target);

        $this->info('Published config/context-gate.php. Every tool is disabled until you list it.');

        return self::SUCCESS;
    }
}
