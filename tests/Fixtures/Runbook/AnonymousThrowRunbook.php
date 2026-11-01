<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures\Runbook;

use ContextGate\Contracts\Runbook;
use RuntimeException;

/** Fake runbook for tests (spec 018 FR-021): steps() throws an anonymous exception class, whose name holds a NUL and a path. */
final class AnonymousThrowRunbook implements Runbook
{
    public function name(): string
    {
        return 'anonymous-throw';
    }

    public function description(): string
    {
        return 'steps() throws an anonymous exception.';
    }

    public function steps(): array
    {
        throw new class ('never printed') extends RuntimeException {};
    }
}
