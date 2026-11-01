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

    public function test_concurrent_processes_count_refusals_within_the_documented_bound(): void
    {
        $this->runWorkers(array_fill(0, self::WORKERS, ['refusals', (string) self::ATTEMPTS]));

        // A separate process reads the settled count, after every writer has exited.
        $count = $this->runWorkers([['refusals-count']])[0]['count'];

        // The key lock is tried once (block(0)); a held lock falls back to an unlocked increment, which can lose counts.
        // So the count is never above the refusals made and never zero, but it may undercount under contention.
        $this->assertLessThanOrEqual(self::WORKERS * self::ATTEMPTS, $count);
        $this->assertGreaterThan(0, $count);
    }
}
