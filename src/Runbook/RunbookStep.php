<?php

declare(strict_types=1);

namespace ContextGate\Runbook;

use Closure;
use ContextGate\Pipeline\Caps;
use InvalidArgumentException;

/**
 * One read-only step of a runbook (spec 018 FR-001, FR-016, FR-018). The run closure receives only the RunbookContext.
 * Name, timeout and output size are checked here; `readOnly` is stored as given and checked by gate:check (FR-006).
 *
 * @internal May change without notice.
 */
final readonly class RunbookStep
{
    /** Names of runbooks, steps and tools (spec 018 R8). */
    public const NAME_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,63}\z/';

    /** @param Closure(RunbookContext): StepResult $run */
    public function __construct(
        public string $name,
        public bool $readOnly,
        public Closure $run,
        public float $timeoutSeconds = 2.5,
        public int $maxBytes = 512,
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("Step name [{$name}] must match ".self::NAME_PATTERN.'.');
        }

        if ($timeoutSeconds <= 0 || $timeoutSeconds > Caps::RUNBOOK_STEP_TIMEOUT) {
            throw new InvalidArgumentException('Step timeout must be above 0 and at most '.Caps::RUNBOOK_STEP_TIMEOUT.' seconds.');
        }

        if ($maxBytes < 1 || $maxBytes > Caps::RUNBOOK_STEP_BYTES) {
            throw new InvalidArgumentException('Step output must be at least 1 and at most '.Caps::RUNBOOK_STEP_BYTES.' bytes.');
        }
    }
}
