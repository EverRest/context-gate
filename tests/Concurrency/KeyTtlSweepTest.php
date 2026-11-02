<?php

declare(strict_types=1);

namespace ContextGate\Tests\Concurrency;

use PHPUnit\Framework\Attributes\Group;

/**
 * Spec 008 US6, SC-004 (T034): after every kind of worker has run against Redis, each key of the run has a time to
 * live, and none is above its family's maximum. Redis only: the file store has no key listing to sweep.
 */
#[Group('concurrency')]
class KeyTtlSweepTest extends ConcurrencyTestCase
{
    public function test_every_key_of_the_run_has_a_ttl_within_its_family_maximum(): void
    {
        if (! $this->redis()) {
            $this->markTestSkipped('The key sweep runs on Redis: CONTEXT_GATE_STORE=redis.');
        }

        $this->runWorkers([
            ['budget-session', '1000', '5', 'sweep-session'],
            ['budget-day', '1000', '5', 'sweep-day'],
            ['budget-calls', '50', '5', 'sweep-calls'],
            ['refusals', '5', '500', 'sweep-refusals'],
            ['pseudonym-mint', 'day:tool', 'alice', '42', '3'],
            ['pseudonym-batch', 'week:tool', 'alice', '0', '5'],
            ['pseudonym-batch', 'session:sweep', '-', '0', '5'],
        ]);

        $keys = $this->redisKeys();
        $this->assertNotSame([], $keys, 'the workers left keys to sweep');

        $redis = $this->redisClient();

        foreach ($keys as $key) {
            $name = substr($key, strlen($this->prefix));
            $max = KeyFamilies::maxTtl($name);

            $this->assertNotNull($max, "{$name} belongs to no key family");
            $ttl = $redis->ttl($key);
            $this->assertGreaterThan(0, $ttl, "{$name} has no expiry");
            $this->assertLessThanOrEqual($max, $ttl, "{$name} lives longer than its family allows");
        }
    }
}
