<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures\Runbook;

use ContextGate\Contracts\Runbook;
use ContextGate\Runbook\RunbookContext;
use ContextGate\Runbook\RunbookStep;
use ContextGate\Runbook\StepResult;
use ContextGate\Runbook\StepStatus;

/** Fake runbook for tests (spec 018): its step asks for a timeout above the 5 second cap, so steps() throws. */
final class OverLimitRunbook implements Runbook
{
    public function name(): string
    {
        return 'over-limit';
    }

    public function description(): string
    {
        return 'A step over its timeout cap.';
    }

    public function steps(): array
    {
        return [new RunbookStep(
            name: 'slow',
            readOnly: true,
            run: fn (RunbookContext $context): StepResult => new StepResult(StepStatus::Ok, 'late'),
            timeoutSeconds: 6.0,
        )];
    }
}
