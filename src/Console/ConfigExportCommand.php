<?php

declare(strict_types=1);

namespace ContextGate\Console;

use ContextGate\Settings\ConfigExporter;
use ContextGate\Settings\EffectiveSettings;
use Illuminate\Console\Command;

/**
 * spec 007 US6 (FR-012): gate:config:export prints the settings in force as JSON, without canary values or secrets.
 *
 * @internal May change without notice.
 */
class ConfigExportCommand extends Command
{
    protected $signature = 'gate:config:export';

    protected $description = 'Print the settings in force as JSON, without canary values or secrets';

    public function handle(): int
    {
        $this->line(json_encode(ConfigExporter::export(EffectiveSettings::document()), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
