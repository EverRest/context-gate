<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures\Runbook;

use ContextGate\Contracts\Runbook;
use ContextGate\Runbook\RunbookContext;
use ContextGate\Runbook\RunbookStep;
use ContextGate\Runbook\StepResult;
use ContextGate\Runbook\StepStatus;

/** Fake runbook for tests (spec 018): a step whose text carries the fake canary value FAKE_FIXTURE_ONLY_CANARY. */
final class CanaryRunbook implements Runbook
{
    public function name(): string
    {
        return 'canary';
    }

    public function description(): string
    {
        return 'A step whose text holds a canary.';
    }

    public function steps(): array
    {
        return [new RunbookStep(
            name: 'leaky',
            readOnly: true,
            run: fn (RunbookContext $c): StepResult => new StepResult(StepStatus::Ok, 'value FAKE_FIXTURE_ONLY_CANARY here'),
        )];
    }
}
