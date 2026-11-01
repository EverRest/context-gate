<?php

declare(strict_types=1);

namespace ContextGate\Console;

use ContextGate\Classification\Ceiling;
use ContextGate\Pipeline\Budget;
use ContextGate\Pipeline\Payload;
use ContextGate\Runbook\Report;
use ContextGate\Runbook\RunbookContext;
use ContextGate\Runbook\RunbookRegistry;
use ContextGate\Runbook\RunbookRowsContract;
use ContextGate\Runbook\RunbookRunner;
use ContextGate\Runbook\StepStatus;
use ContextGate\Server\Caller;
use ContextGate\Server\FirewallRunner;
use ContextGate\Server\Failure;
use ContextGate\Server\ReasonCode;
use Illuminate\Cache\RateLimiter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * gate:runbook {name} (spec 018 FR-013 to FR-015): runs a registered runbook whether or not its tool is enabled, prints
 * one row per step and writes no receipt. An error step leaves one operator note on the receipt channel.
 *
 * @internal May change without notice.
 */
class RunbookCommand extends Command
{
    protected $signature = 'gate:runbook {name : Runbook name as configured under context-gate.runbooks}';

    protected $description = 'Run a registered read-only runbook and print one row per step';

    /** Summary order: the most severe status first. */
    private const SEVERITY = ['error', 'timeout', 'fail', 'warn', 'ok'];

    public function handle(): int
    {
        $argument = $this->argument('name');
        $name = is_string($argument) ? $argument : '';
        $registry = RunbookRegistry::fromConfig();
        $runbook = $registry->find($name);

        if ($runbook === null) {
            $this->line("runbook not found: {$name}");
            $this->line('registered: '.implode(', ', array_keys($registry->all())));

            return 2;
        }

        $onError = static function (string $step, Throwable $e): void {
            Failure::note($e, 'gate:runbook');
        };

        $report = Report::fromResults((new RunbookRunner(onError: $onError))->run($runbook, new RunbookContext()));

        // The same firewall stages as the MCP tool (FR-010): redaction, canary check, untrusted marking.
        $store = Cache::build(['driver' => 'array']);
        $checked = (new FirewallRunner())->firewall(
            new Caller('operator', []),
            "runbook.{$name}",
            new RunbookRowsContract(),
            Ceiling::Internal,
            new Budget($store, new RateLimiter($store), 'operator', 'operator', 3600),
            $report->rows,
        );

        if (! $checked instanceof Payload) {
            $this->line($checked === ReasonCode::CanaryHit ? 'Output withheld: a canary value was found.' : 'Output withheld: the output did not pass the firewall.');

            return 1;
        }

        $rows = array_map(static fn (array $row): array => ['step' => (string) $row['step'], 'status' => (string) $row['status'], 'text' => self::text($row['text'])], $checked->rows);

        $this->line("runbook: {$name} (".count($runbook->steps()).' steps)');
        $this->printRows($rows);
        $this->line('summary: '.$this->summary($rows).'; '.$report->droppedRows.' rows dropped');

        $exit = 0;

        foreach ($rows as $row) {
            $exit = max($exit, StepStatus::from($row['status'])->exitCode());
        }

        return $exit;
    }

    /** A text field after quarantine is the wrapper; the printed text is its inner text. */
    private static function text(mixed $value): string
    {
        return is_array($value) ? (string) ($value['text'] ?? '') : (string) $value;
    }

    /** @param list<array{step: string, status: string, text: string}> $rows */
    private function printRows(array $rows): void
    {
        $width = max([strlen('step'), ...array_map(static fn (array $row): int => strlen($row['step']), $rows)]);

        $this->line(str_pad('step', $width).'  status  text');

        foreach ($rows as $row) {
            $this->line(str_pad($row['step'], $width).'  '.str_pad($row['status'], 6).'  '.$row['text']);
        }
    }

    /** @param list<array{step: string, status: string, text: string}> $rows */
    private function summary(array $rows): string
    {
        $counts = array_count_values(array_column($rows, 'status'));
        $parts = [];

        foreach (self::SEVERITY as $status) {
            if (($counts[$status] ?? 0) > 0) {
                $parts[] = "{$counts[$status]} {$status}";
            }
        }

        return implode(', ', $parts);
    }
}
