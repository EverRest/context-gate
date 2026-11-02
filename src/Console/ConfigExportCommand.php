<?php

declare(strict_types=1);

namespace ContextGate\Console;

use ContextGate\Settings\ActiveSettings;
use ContextGate\Settings\ConfigExporter;
use Illuminate\Console\Command;

/**
 * gate:config:export prints the settings in force as JSON, without canary values or secrets.
 *
 * @internal May change without notice.
 */
class ConfigExportCommand extends Command
{
    protected $signature = 'gate:config:export';

    protected $description = 'Print the settings in force as JSON, without canary values or secrets';

    public function handle(): int
    {
        $this->line(json_encode(ConfigExporter::export(app(ActiveSettings::class)->document()), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
