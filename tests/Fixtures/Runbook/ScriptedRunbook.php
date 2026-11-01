<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures\Runbook;

use ContextGate\Contracts\Runbook;
use ContextGate\Runbook\RunbookStep;

/** Fake runbook for tests (spec 018): runs the steps it is given, in order. */
final class ScriptedRunbook implements Runbook
{
    /** @param list<RunbookStep> $steps */
    public function __construct(
        private readonly array $steps,
        private readonly string $runbookName = 'scripted',
        private readonly string $runbookDescription = 'Scripted steps for tests.',
    ) {
    }

    public function name(): string
    {
        return $this->runbookName;
    }

    public function description(): string
    {
        return $this->runbookDescription;
    }

    public function steps(): array
    {
        return $this->steps;
    }
}
