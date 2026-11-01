<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures\Runbook;

use ContextGate\Contracts\Runbook;
use ContextGate\Runbook\RunbookContext;
use ContextGate\Runbook\RunbookStep;
use ContextGate\Runbook\StepResult;
use ContextGate\Runbook\StepStatus;

/** Fake runbook for tests (spec 018): a step that returns after its 1 ms timeout. */
final class SlowRunbook implements Runbook
{
    public function name(): string
    {
        return 'slow';
    }

    public function description(): string
    {
        return 'A step that returns late.';
    }

    public function steps(): array
    {
        return [new RunbookStep(
            name: 'late',
            readOnly: true,
            run: function (RunbookContext $c): StepResult {
                usleep(20000);

                return new StepResult(StepStatus::Ok, 'late text');
            },
            timeoutSeconds: 0.001,
        )];
    }
}
