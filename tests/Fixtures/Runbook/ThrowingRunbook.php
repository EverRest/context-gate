<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures\Runbook;

use ContextGate\Contracts\Runbook;
use ContextGate\Runbook\RunbookContext;
use ContextGate\Runbook\RunbookStep;
use ContextGate\Runbook\StepResult;
use ContextGate\Runbook\StepStatus;
use RuntimeException;

/** Fake runbook for tests (spec 018): a step that throws with a message that must never be printed. */
final class ThrowingRunbook implements Runbook
{
    public function name(): string
    {
        return 'throwing';
    }

    public function description(): string
    {
        return 'A step that throws.';
    }

    public function steps(): array
    {
        return [
            new RunbookStep(name: 'boom', readOnly: true, run: function (RunbookContext $c): StepResult {
                throw new RuntimeException('SECRET_MESSAGE_NEVER_PRINTED');
            }),
            new RunbookStep(name: 'after', readOnly: true, run: fn (RunbookContext $c): StepResult => new StepResult(StepStatus::Ok, 'still runs')),
        ];
    }
}
