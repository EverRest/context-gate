<?php

declare(strict_types=1);

namespace ContextGate\Tests\Concurrency;

use PHPUnit\Framework\Attributes\Group;

/** The refusal counter across real processes on one file store (opt-in, see ConcurrencyTestCase). */
#[Group('concurrency')]
class RefusalLedgerProcessTest extends ConcurrencyTestCase
{
    private const WORKERS = 8;

    private const ATTEMPTS = 20;

    public function test_concurrent_processes_count_every_refusal_exactly(): void
    {
        $this->runWorkers(array_fill(0, self::WORKERS, ['refusals', (string) self::ATTEMPTS]));

        // A separate process reads the settled count, after every writer has exited.
        $count = $this->runWorkers([['refusals-count']])[0]['count'];

        $this->assertSame(self::WORKERS * self::ATTEMPTS, $count);
    }
}
