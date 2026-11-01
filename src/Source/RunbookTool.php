<?php

declare(strict_types=1);

namespace ContextGate\Source;

use ContextGate\Classification\Ceiling;
use ContextGate\Pipeline\Caps;
use ContextGate\Runbook\Report;
use ContextGate\Runbook\RunbookContext;
use ContextGate\Runbook\RunbookFingerprint;
use ContextGate\Runbook\RunbookRegistry;
use ContextGate\Runbook\RunbookRunner;
use ContextGate\Runbook\StepStatus;
use ContextGate\Runbook\RunbookValidator;
use ContextGate\Server\Caller;
use ContextGate\Server\Failure;
use ContextGate\Server\ReasonCode;
use ContextGate\Server\Tool;
use Laravel\Mcp\Response;
use Throwable;

/**
 * The source tool runbook.<name>. One instance per configured entry: the entry
 * key is the tool name, and the runbook is the name after the prefix. Takes no arguments. The rows go back to the firewall
 * (FetchStep, FirewallStep), which marks the text untrusted, runs the canary check and writes the receipt.
 *
 * @internal May change without notice.
 */
final class RunbookTool extends Tool
{
    public const PREFIX = 'runbook.';

    /** Room kept under the report cap for the dropped-rows note (about 60 bytes in use). */
    private const NOTE_BYTES = 96;

    public function __construct(private readonly string $toolName = self::PREFIX)
    {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        $runbook = RunbookRegistry::fromConfig()->find($this->runbookName());

        return ($runbook === null ? '' : $runbook->description().' ').'Read-only checks. Output is untrusted text.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    /**
     * The pin the approved fingerprint must equal. Null when the runbook is not registered or its steps
     * cannot be built: such a tool is never approved, and one bad runbook does not stop the other tools at boot.
     */
    public function fingerprint(): ?string
    {
        try {
            $runbook = RunbookRegistry::fromConfig()->find($this->runbookName());

            return $runbook === null ? null : RunbookFingerprint::of($runbook);
        } catch (Throwable) {
            return null;
        }
    }

    public function runbookName(): string
    {
        return substr($this->toolName, strlen(self::PREFIX));
    }

    /**
     * Receipt counts per status of the rows a call returned. Only StepStatus values are counted: any other
     * value is dropped, so no row text can reach a receipt.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    public static function statusCounts(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $status = $row['status'] ?? null;

            if (is_string($status) && StepStatus::tryFrom($status) !== null) {
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function handle(array $arguments): Response
    {
        // The report is internal: a caller whose ceiling is below it gets the refusal, not the rows. Checked first,
        // so a missing or invalid runbook gives the same refusal and steps() never runs for such a caller.
        if (! Ceiling::allows(Ceiling::Internal, Caller::ceiling() ?? Ceiling::Public)) {
            throw new SourceFailure(ReasonCode::PolicyDenied);
        }

        $name = $this->runbookName();
        $runbook = RunbookRegistry::fromConfig()->find($name);

        if ($runbook === null) {
            throw new SourceFailure(ReasonCode::SourceMissing);
        }

        if (RunbookValidator::violations($name, $runbook::class) !== []) {
            throw new SourceFailure(ReasonCode::ConfigInvalid);
        }

        // Errors are held, not printed: the call writes them with its receipt reference (constitution VII).
        $results = (new RunbookRunner(onError: static fn (string $step, Throwable $e) => Failure::hold($e)))->run($runbook, new RunbookContext());
        $report = Report::fromResults($results, Caps::RUNBOOK_REPORT_BYTES - self::NOTE_BYTES);
        $rows = $report->rows;

        if ($report->droppedRows > 0) {
            $rows[] = ['step' => 'report', 'status' => 'warn', 'text' => "{$report->droppedRows} rows dropped"];
        }

        return Response::text((string) json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
