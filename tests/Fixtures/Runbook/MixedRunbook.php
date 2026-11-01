<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures\Runbook;

use ContextGate\Contracts\Runbook;
use ContextGate\Runbook\RunbookContext;
use ContextGate\Runbook\RunbookStep;
use ContextGate\Runbook\StepResult;
use ContextGate\Runbook\StepStatus;

/** Fake runbook for tests (spec 018): one ok, one warn and one fail step, in that order. */
final class MixedRunbook implements Runbook
{
    public function name(): string
    {
        return 'mixed';
    }

    public function description(): string
    {
        return 'One ok, one warn and one fail step.';
    }

    public function steps(): array
    {
        return [
            new RunbookStep(name: 'ok_step', readOnly: true, run: fn (RunbookContext $c): StepResult => new StepResult(StepStatus::Ok, 'first is ok')),
            new RunbookStep(name: 'warn_step', readOnly: true, run: fn (RunbookContext $c): StepResult => new StepResult(StepStatus::Warn, 'second warns')),
            new RunbookStep(name: 'fail_step', readOnly: true, run: fn (RunbookContext $c): StepResult => new StepResult(StepStatus::Fail, 'third fails')),
        ];
    }
}
