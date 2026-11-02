<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use ContextGate\Pipeline\Budget;
use ContextGate\Pipeline\Payload;
use ContextGate\Support\StoreUnavailable;
use ContextGate\Tests\TestCase;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;

class BudgetLockTimeoutTest extends TestCase
{
    /** A day-counter lock that times out after the session reservation was made: the session bytes are given back. */
    public function test_a_day_lock_timeout_releases_the_session_reservation_and_refuses(): void
    {
        $store = new class () extends ArrayStore {
            public function lock($name, $seconds = 0, $owner = null)
            {
                if (! str_contains((string) $name, 'budget:day:')) {
                    return parent::lock($name, $seconds, $owner);
                }

                return new class ($this, $name, $seconds, $owner) extends ArrayLock {
                    public function block($seconds, $callback = null)
                    {
                        throw new \Illuminate\Contracts\Cache\LockTimeoutException();
                    }
                };
            }
        };
        $cache = new Repository($store);
        $cache->put('budget:session:s5', 300, 3600);
        $budget = new Budget($cache, new RateLimiter($cache), 'alice', 's5', 3600, perCall: 500, perSession: 100_000);

        // Spec 008 US4: a lock not acquired in time is a store failure, refused as StoreUnavailable.
        $this->expectException(StoreUnavailable::class);

        try {
            $budget->handle($this->payloadOf(400), static fn (Payload $p): Payload => $p);
        } finally {
            $this->assertSame(300, $cache->get('budget:session:s5'), 'the session reservation is given back');
        }
    }

    private function payloadOf(int $bytes): Payload
    {
        return new Payload([['v' => str_repeat('a', $bytes)]]);
    }
}
