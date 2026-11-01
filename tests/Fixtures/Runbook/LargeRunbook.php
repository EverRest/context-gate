<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures\Runbook;

use ContextGate\Contracts\Runbook;
use ContextGate\Runbook\RunbookContext;
use ContextGate\Runbook\RunbookStep;
use ContextGate\Runbook\StepResult;
use ContextGate\Runbook\StepStatus;

/** Fake runbook for tests (spec 018): twenty steps of 2000 bytes each, so the report overflows its 8 KiB cap. */
final class LargeRunbook implements Runbook
{
    public function name(): string
    {
        return 'large';
    }

    public function description(): string
    {
        return 'Twenty long steps.';
    }

    public function steps(): array
    {
        $steps = [];

        for ($i = 1; $i <= 20; $i++) {
            $steps[] = new RunbookStep(
                name: 'step_'.$i,
                readOnly: true,
                run: fn (RunbookContext $c): StepResult => new StepResult(StepStatus::Ok, str_repeat('word ', 400)),
                maxBytes: 2048,
            );
        }

        return $steps;
    }
}
