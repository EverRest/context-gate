<?php

declare(strict_types=1);

namespace ContextGate\Console;

use ContextGate\Settings\ActiveSettings;
use ContextGate\Settings\ConfigImporter;
use ContextGate\Settings\ConfigVersionStore;
use Illuminate\Console\Command;

/**
 * gate:config:import {file}. Prints every violation and exits 1 when the file is refused.
 *
 * @internal May change without notice.
 */
class ConfigImportCommand extends Command
{
    protected $signature = 'gate:config:import {file : Path to a settings JSON file}';

    protected $description = 'Validate a settings file as a whole and, when valid, apply it; an invalid file changes nothing';

    public function handle(): int
    {
        $file = $this->argument('file');
        $caller = app('context-gate.caller');
        $violations = ConfigImporter::import(is_string($file) ? $file : '', app(ActiveSettings::class)->environment(), is_string($caller) ? $caller : 'unknown');

        if ($violations !== []) {
            foreach ($violations as $violation) {
                $this->error("{$violation['key']}: {$violation['message']}");
            }

            return self::FAILURE;
        }

        $this->info('Applied version '.ConfigVersionStore::fromApp()->active());

        return self::SUCCESS;
    }
}
