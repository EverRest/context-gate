<?php

declare(strict_types=1);

namespace ContextGate\Console;

use ContextGate\Settings\ActiveSettings;
use ContextGate\Classification\Catalog;
use ContextGate\ContextGateServiceProvider;
use ContextGate\Readiness\Audit;
use ContextGate\Readiness\AuditEntry;
use Illuminate\Console\Command;

/**
 * Lists what the assistant can see right now. Reads config and contracts only; writes nothing.
 *
 * @internal May change without notice.
 */
class AuditCommand extends Command
{
    use FailsToStderr;

    protected $signature = 'gate:audit {--json : Print the report as JSON}';

    protected $description = 'List the enabled tools with their risk, ceiling, field classes, contract and rule hashes';

    public function handle(): int
    {
        $settings = app(ActiveSettings::class);

        if (! $settings->valid()) {
            $violation = $settings->violations()[0];

            return $this->failWith("{$violation['key']} {$violation['message']}");
        }

        $tools = [];

        foreach ($settings->tools() as $name => $tool) {
            $tools[$name] = ['enabled' => $tool->enabled, 'class' => $tool->class, 'contract' => $tool->contract, 'risk' => $tool->risk];
        }

        $audit = new Audit(
            $tools,
            $settings->ceiling()->value,
            new Catalog($settings->classification()),
            ContextGateServiceProvider::ruleHashes(),
        );
        $entries = $audit->entries();

        $enabled = array_values(array_filter($entries, static fn (AuditEntry $entry): bool => $entry->enabled));
        $notEnabled = array_values(array_filter($entries, static fn (AuditEntry $entry): bool => ! $entry->enabled));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'tools' => array_map(static fn (AuditEntry $entry): array => $entry->toArray(), $enabled),
                'not_enabled' => array_map(static fn (AuditEntry $entry): string => $entry->tool, $notEnabled),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($enabled as $entry) {
            $this->line($entry->tool);
            $this->line('  risk: '.($entry->risk ?? 'unset'));
            $this->line("  ceiling: {$entry->ceiling}");
            $this->line('  field classes: '.$this->classes($entry->fieldClasses));
            $this->line('  contract: '.($entry->contract ?? 'none'));
            $this->line("  rules: tool={$entry->rules['tool']} contract={$entry->rules['contract']} classification={$entry->rules['classification']}");
            $this->line('');
        }

        $this->line('Not enabled:');

        foreach ($notEnabled as $entry) {
            $this->line("  {$entry->tool}");
        }

        return self::SUCCESS;
    }

    /** @param array<string, int> $counts */
    private function classes(array $counts): string
    {
        if ($counts === []) {
            return 'none';
        }

        return implode(', ', array_map(static fn (string $class, int $count): string => "{$class}={$count}", array_keys($counts), $counts));
    }
}
