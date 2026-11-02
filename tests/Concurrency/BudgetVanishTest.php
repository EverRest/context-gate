<?php

declare(strict_types=1);

namespace ContextGate\Tests\Concurrency;

use PHPUnit\Framework\Attributes\Group;

/** Spec 008 US6 #2 (T035): a counter that vanishes between add and increment gets its TTL back in the same reservation. */
#[Group('concurrency')]
class BudgetVanishTest extends ConcurrencyTestCase
{
    public function test_a_day_counter_that_vanishes_between_add_and_increment_gets_its_ttl_again(): void
    {
        if (! $this->redis()) {
            $this->markTestSkipped('The vanish is simulated in the Redis store: CONTEXT_GATE_STORE=redis.');
        }

        $caller = 'vanish-'.bin2hex(random_bytes(4));
        [$result] = $this->runWorkers([['budget-vanish', $caller]]);

        $this->assertSame(1, $result['served'], 'the reservation is served');

        $key = $this->prefix.'context-gate:budget:day:'.$caller.':'.gmdate('Y-m-d');
        $redis = $this->redisClient();

        $this->assertGreaterThan(0, $redis->ttl($key), 'the recreated day counter has an expiry');
        $this->assertLessThanOrEqual(86400, $redis->ttl($key));
        $this->assertGreaterThan(0, (int) $redis->get($key), 'the reservation is counted');
    }
}
