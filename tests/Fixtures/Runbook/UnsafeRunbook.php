<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures\Runbook;

use ContextGate\Contracts\Runbook;
use ContextGate\Runbook\RunbookContext;
use ContextGate\Runbook\RunbookStep;
use ContextGate\Runbook\StepResult;
use ContextGate\Runbook\StepStatus;

/** Fake runbook for tests (spec 018 US3): one step not declared read-only, which the validator refuses. */
final class UnsafeRunbook implements Runbook
{
    public function name(): string
    {
        return 'unsafe';
    }

    public function description(): string
    {
        return 'A step not declared read-only.';
    }

    public function steps(): array
    {
        return [new RunbookStep(
            name: 'writes',
            readOnly: false,
            run: fn (RunbookContext $c): StepResult => new StepResult(StepStatus::Ok, 'would write'),
        )];
    }
}
