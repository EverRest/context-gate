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
use Illuminate\Contracts\Cache\LockTimeoutException;

/** Spec 008 US4 (T013): a store that fails or times out on a lock refuses the call with StoreUnavailable. */
class BudgetGuardTest extends TestCase
{
    /** A store whose increments fail with a message that must never leave the gate. */
    private function failingIncrements(): Repository
    {
        $store = new class () extends ArrayStore {
            public function increment($key, $value = 1)
            {
                throw new \RuntimeException('connect tcp://10.0.0.1:6379 password=FAKE_FIXTURE_ONLY');
            }
        };

        return new Repository($store);
    }

    /** A store whose day-budget lock times out; every other lock is granted. */
    private function timingOutDayLock(): Repository
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
                        throw new LockTimeoutException();
                    }
                };
            }
        };

        return new Repository($store);
    }

    private function budget(Repository $cache): Budget
    {
        return new Budget($cache, new RateLimiter($cache), 'alice', 's1', 3600, perCall: 500, perSession: 100_000);
    }

    public function test_an_increment_failure_refuses_admit(): void
    {
        $this->expectException(StoreUnavailable::class);

        $this->budget($this->failingIncrements())->admit();
    }

    public function test_an_increment_failure_refuses_the_reservation_without_its_message(): void
    {
        $caught = null;

        try {
            $this->budget($this->failingIncrements())->handle(new Payload([['v' => 'a']]), static fn (Payload $p): Payload => $p);
        } catch (StoreUnavailable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(StoreUnavailable::class, $caught);
        $this->assertStringNotContainsString('10.0.0.1', $caught->getMessage());
        $this->assertStringNotContainsString('FAKE_FIXTURE_ONLY', $caught->getMessage());
    }

    public function test_a_lock_timeout_refuses_the_reservation_and_gives_the_session_bytes_back(): void
    {
        $cache = $this->timingOutDayLock();
        $cache->put('context-gate:budget:session:s1', 300, 3600);

        $caught = null;
        try {
            $this->budget($cache)->handle(new Payload([['v' => str_repeat('a', 400)]]), static fn (Payload $p): Payload => $p);
        } catch (StoreUnavailable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(StoreUnavailable::class, $caught);
        $this->assertInstanceOf(LockTimeoutException::class, $caught->getPrevious());
        $this->assertSame(300, $cache->get('context-gate:budget:session:s1'), 'the session reservation is given back');
    }

    /** Review finding: the retry time read after an over-limit call is a store read too; its failure refuses the call. */
    public function test_a_failure_reading_the_retry_time_refuses_with_store_unavailable(): void
    {
        $cache = new Repository(new ArrayStore());
        $limiter = new class ($cache) extends RateLimiter {
            public function availableIn($key): int
            {
                throw new \RuntimeException('connect tcp://10.0.0.1:6379 password=FAKE_FIXTURE_ONLY');
            }
        };
        $budget = new Budget($cache, $limiter, 'alice', 's1', 3600, perMinute: 1);
        $this->assertNull($budget->admit(), 'the first call is admitted');

        $this->expectException(StoreUnavailable::class);

        $budget->admit();
    }
}
