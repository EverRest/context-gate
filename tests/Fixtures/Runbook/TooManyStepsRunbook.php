<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures\Runbook;

use ContextGate\Contracts\Runbook;
use ContextGate\Runbook\RunbookContext;
use ContextGate\Runbook\RunbookStep;
use ContextGate\Runbook\StepResult;
use ContextGate\Runbook\StepStatus;

/** Fake runbook for tests (spec 018 US5): twenty-one read-only steps, one more than the cap. */
final class TooManyStepsRunbook implements Runbook
{
    public function name(): string
    {
        return 'too-many';
    }

    public function description(): string
    {
        return 'Twenty-one steps.';
    }

    public function steps(): array
    {
        $steps = [];

        for ($i = 1; $i <= 21; $i++) {
            $steps[] = new RunbookStep(
                name: 'step_'.$i,
                readOnly: true,
                run: fn (RunbookContext $c): StepResult => new StepResult(StepStatus::Ok, 'ok'),
            );
        }

        return $steps;
    }
}
