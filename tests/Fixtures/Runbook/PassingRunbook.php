<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures\Runbook;

use ContextGate\Contracts\Runbook;
use ContextGate\Runbook\RunbookContext;
use ContextGate\Runbook\RunbookStep;
use ContextGate\Runbook\StepResult;
use ContextGate\Runbook\StepStatus;

/** Fake runbook for tests (spec 018): one passing step, no secrets, no writes. */
final class PassingRunbook implements Runbook
{
    public function name(): string
    {
        return 'passing';
    }

    public function description(): string
    {
        return 'One step that passes.';
    }

    public function steps(): array
    {
        return [new RunbookStep(
            name: 'always_ok',
            readOnly: true,
            run: fn (RunbookContext $context): StepResult => new StepResult(StepStatus::Ok, 'fixture is ok'),
        )];
    }
}
