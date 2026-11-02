<?php

declare(strict_types=1);

namespace ContextGate\Tests\Concurrency;

use PHPUnit\Framework\Attributes\Group;

/** Reserve-then-check across real processes sharing one cache store (file or Redis, see ConcurrencyTestCase). */
#[Group('concurrency')]
class BudgetProcessTest extends ConcurrencyTestCase
{
    private const WORKERS = 8;

    /** Each served row is exactly this many bytes once encoded as a rows list (see worker.php). */
    private const ROW_PAD = 90;

    /** Each repetition gets its own session, caller and day counter: a counter from an earlier run must not count. */
    public function test_concurrent_processes_never_serve_more_than_the_session_limit(): void
    {
        $rowBytes = strlen((string) json_encode([['v' => str_repeat('a', self::ROW_PAD)]]));
        $slots = 10;
        $limit = $slots * $rowBytes;

        for ($round = 0; $round < $this->repeats(); $round++) {
            $session = 'session-'.bin2hex(random_bytes(4));
            $results = $this->runWorkers(array_fill(0, self::WORKERS, ['budget-session', (string) $limit, '20', $session]));

            $bytes = array_sum(array_column($results, 'bytes'));
            $served = array_sum(array_column($results, 'served'));

            $this->assertLessThanOrEqual($limit, $bytes, 'the processes together were granted more bytes than the limit');
            $this->assertSame($slots, $served, 'the limit should be filled exactly, with no lost capacity');
        }
    }

    public function test_concurrent_processes_never_serve_more_than_the_day_limit(): void
    {
        $rowBytes = strlen((string) json_encode([['v' => str_repeat('a', self::ROW_PAD)]]));
        $slots = 10;
        $limit = $slots * $rowBytes;

        for ($round = 0; $round < $this->repeats(); $round++) {
            $caller = 'day-'.bin2hex(random_bytes(4));
            $results = $this->runWorkers(array_fill(0, self::WORKERS, ['budget-day', (string) $limit, '20', $caller]));

            $bytes = array_sum(array_column($results, 'bytes'));
            $served = array_sum(array_column($results, 'served'));

            $this->assertLessThanOrEqual($limit, $bytes, 'the processes together were granted more bytes than the day limit');
            $this->assertSame($slots, $served, 'the day limit should be filled exactly, with no lost capacity');
        }
    }

    public function test_concurrent_processes_never_admit_more_calls_than_the_per_minute_limit(): void
    {
        $perMinute = 20;

        for ($round = 0; $round < $this->repeats(); $round++) {
            $caller = 'calls-'.bin2hex(random_bytes(4));
            $results = $this->runWorkers(array_fill(0, self::WORKERS, ['budget-calls', (string) $perMinute, '10', $caller]));

            $admitted = array_sum(array_column($results, 'admitted'));

            $this->assertLessThanOrEqual($perMinute, $admitted, 'the processes together admitted more calls than the limit');
            $this->assertSame($perMinute, $admitted);
        }
    }
}
