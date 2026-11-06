<?php

declare(strict_types=1);

namespace ContextGate\Tests\Concurrency;

use PHPUnit\Framework\Attributes\Group;

/** A refusal counter that expires between add and increment gets a TTL again instead of living forever. */
#[Group('concurrency')]
class RefusalVanishTest extends ConcurrencyTestCase
{
    public function test_a_credential_counter_that_vanishes_between_add_and_increment_gets_its_ttl_again(): void
    {
        if (! $this->redis()) {
            $this->markTestSkipped('The vanish is simulated in the Redis store: CONTEXT_GATE_STORE=redis.');
        }

        $bearer = 'vanish-'.bin2hex(random_bytes(4));
        $this->runWorkers([['refusals-vanish', $bearer]]);

        $redis = $this->redisClient();
        $keys = array_values(array_filter($this->redisKeys(), static fn (string $key): bool => str_contains($key, 'refusals:cred:')));

        $this->assertCount(1, $keys);
        $this->assertSame(1, (int) $redis->get($keys[0]), 'the refusal after the vanish is counted from zero');
        $this->assertGreaterThan(0, $redis->ttl($keys[0]), 'the recreated counter has an expiry');
        $this->assertLessThanOrEqual(60, $redis->ttl($keys[0]));
    }
}
