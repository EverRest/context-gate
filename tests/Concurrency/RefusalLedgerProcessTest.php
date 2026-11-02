<?php

declare(strict_types=1);

namespace ContextGate\Tests\Concurrency;

use PHPUnit\Framework\Attributes\Group;

/** The refusal counter across real processes (opt-in, see ConcurrencyTestCase). */
#[Group('concurrency')]
class RefusalLedgerProcessTest extends ConcurrencyTestCase
{
    private const WORKERS = 8;

    private const ATTEMPTS = 20;

    /** Each repetition is one (IP, credential) pair of its own: a counter from an earlier run must not count. */
    public function test_concurrent_processes_count_refusals_within_the_documented_bound(): void
    {
        if ($this->redis()) {
            $this->markTestSkipped('The exact count is asserted on Redis below; this bound is the file store check.');
        }

        for ($round = 0; $round < $this->repeats(); $round++) {
            $bearer = 'file-'.bin2hex(random_bytes(4));
            $this->runWorkers(array_fill(0, self::WORKERS, ['refusals', (string) self::ATTEMPTS, '500', $bearer]));

            $count = $this->runWorkers([['refusals-count', $bearer]])[0]['count'];

            // The file store has no atomic increment across processes: a race can undercount, never overcount.
            $this->assertLessThanOrEqual(self::WORKERS * self::ATTEMPTS, $count);
            $this->assertGreaterThan(0, $count);
        }
    }

    public function test_on_redis_every_refusal_is_counted_exactly(): void
    {
        if (! $this->redis()) {
            $this->markTestSkipped('Exact counts need Redis: CONTEXT_GATE_STORE=redis.');
        }

        for ($round = 0; $round < $this->repeats(); $round++) {
            $bearer = 'exact-'.bin2hex(random_bytes(4));
            $this->runWorkers(array_fill(0, self::WORKERS, ['refusals', (string) self::ATTEMPTS, '500', $bearer]));

            $count = $this->runWorkers([['refusals-count', $bearer]])[0]['count'];

            $this->assertSame(self::WORKERS * self::ATTEMPTS, $count, 'Redis increments are atomic: no refusal may be lost');
        }
    }

    public function test_on_redis_one_throttle_receipt_is_written_when_the_pair_reaches_its_limit(): void
    {
        if (! $this->redis()) {
            $this->markTestSkipped('The single-receipt check runs on Redis: CONTEXT_GATE_STORE=redis.');
        }

        $limit = 50; // inside the 160 refusals the workers make together

        for ($round = 0; $round < $this->repeats(); $round++) {
            $bearer = 'trip-'.bin2hex(random_bytes(4));
            $before = $this->throttleReceipts();
            $this->runWorkers(array_fill(0, self::WORKERS, ['refusals', (string) self::ATTEMPTS, (string) $limit, $bearer]));

            // The receipts file is shared by the rounds of this test: only this round's lines count.
            $this->assertSame(1, $this->throttleReceipts() - $before, 'exactly one http.throttled receipt across all workers');
            $this->assertSame(self::WORKERS * self::ATTEMPTS, $this->runWorkers([['refusals-count', $bearer]])[0]['count']);
        }
    }

    private function throttleReceipts(): int
    {
        $file = $this->base.'/receipts.log';
        $lines = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];

        return count(array_filter($lines, static fn (string $line): bool => str_contains($line, '"reason":"http.throttled"')));
    }
}
