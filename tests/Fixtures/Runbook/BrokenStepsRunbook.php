<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures\Runbook;

use ContextGate\Contracts\Runbook;
use InvalidArgumentException;

/** Fake runbook for tests (spec 018 FR-021): steps() throws, and the message holds a fake secret that must never print. */
final class BrokenStepsRunbook implements Runbook
{
    public function name(): string
    {
        return 'broken';
    }

    public function description(): string
    {
        return 'Steps that cannot be built.';
    }

    public function steps(): array
    {
        throw new InvalidArgumentException('leaks FAKE_FIXTURE_ONLY_SECRET');
    }
}
