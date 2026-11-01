<?php

declare(strict_types=1);

namespace ContextGate\Runbook;

use Closure;
use ContextGate\Contracts\Runbook;
use ContextGate\Pipeline\Caps;
use Throwable;
use UnexpectedValueException;

/**
 * Runs a runbook's steps in declared order (spec 018 FR-016, FR-017). PHP cannot preempt a step (research R1), so a
 * timeout is judged after the step returns, and the run budget is checked before each step (R2). A throwing step is
 * `error` with a fixed text; the exception goes only to $onError (FR-015, constitution VII).
 *
 * @internal May change without notice.
 */
final class RunbookRunner
{
    public const ERROR_TEXT = 'Step failed. See the operator note.';

    public const TIMEOUT_TEXT = 'Step returned after its time limit; text discarded.';

    public const NOT_RUN_TEXT = 'Not run: run limit reached.';

    private const CUT_MARKER = ' [cut]';

    private readonly Closure $clock;

    /**
     * @param (Closure(): float)|null $clock monotonic seconds; hrtime when null
     * @param (Closure(string, Throwable): void)|null $onError receives the step name and the exception for the operator note
     */
    public function __construct(?Closure $clock = null, private readonly ?Closure $onError = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    /** @return list<array{step: string, result: StepResult}> one row per step, in declared order */
    public function run(Runbook $runbook, RunbookContext $context): array
    {
        $rows = [];
        $started = ($this->clock)();

        foreach ($runbook->steps() as $step) {
            $rows[] = ['step' => $step->name, 'result' => $this->runStep($step, $context, $started)];
        }

        return $rows;
    }

    private function runStep(RunbookStep $step, RunbookContext $context, float $started): StepResult
    {
        if (($this->clock)() - $started >= Caps::RUNBOOK_RUN_SECONDS) {
            return new StepResult(StepStatus::Timeout, self::NOT_RUN_TEXT);
        }

        $begin = ($this->clock)();

        try {
            /** @var mixed $result a step is untrusted host code: its return type is not enforced by the closure */
            $result = ($step->run)($context);

            if (! $result instanceof StepResult) {
                throw new UnexpectedValueException('a step must return a StepResult');
            }
        } catch (Throwable $e) {
            if ($this->onError !== null) {
                ($this->onError)($step->name, $e);
            }

            return new StepResult(StepStatus::Error, self::ERROR_TEXT);
        }

        if (($this->clock)() - $begin > $step->timeoutSeconds) {
            return new StepResult(StepStatus::Timeout, self::TIMEOUT_TEXT);
        }

        return new StepResult($result->status, self::cut($result->text, $step->maxBytes));
    }

    /** Cuts at a UTF-8 boundary; the marker counts toward the limit when there is room for it. */
    private static function cut(string $text, int $bytes): string
    {
        if (strlen($text) <= $bytes) {
            return $text;
        }

        if ($bytes <= strlen(self::CUT_MARKER)) {
            return mb_strcut($text, 0, $bytes, 'UTF-8');
        }

        return mb_strcut($text, 0, $bytes - strlen(self::CUT_MARKER), 'UTF-8').self::CUT_MARKER;
    }
}
