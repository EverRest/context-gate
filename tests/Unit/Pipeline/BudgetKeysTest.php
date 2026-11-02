<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use ContextGate\Pipeline\Budget;
use ContextGate\Pipeline\Payload;
use ContextGate\Tests\Fakes\RecordingStore;
use ContextGate\Tests\TestCase;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;

/** Spec 008 US5 (T022): every key the budget writes, locks included, is under the package namespace. */
class BudgetKeysTest extends TestCase
{
    /** @return list<string> */
    private function touchedBy(callable $run): array
    {
        $store = new RecordingStore();
        $cache = new Repository($store);
        $run(new Budget($cache, new RateLimiter($cache), 'alice', 's1', 3600, perCall: 500, perSession: 100_000));

        return array_values(array_unique($store->touched));
    }

    public function test_admit_writes_the_rate_limit_under_the_namespace(): void
    {
        $keys = $this->touchedBy(static fn (Budget $budget) => $budget->admit());

        $this->assertContains('context-gate:rate:alice', $keys);
        $this->assertContains('context-gate:rate:alice:timer', $keys);
    }

    public function test_handle_writes_the_session_and_day_counters_under_the_namespace(): void
    {
        $keys = $this->touchedBy(static fn (Budget $budget) => $budget->handle(new Payload([['v' => 'a']]), static fn (Payload $p): Payload => $p));

        $this->assertContains('context-gate:budget:session:s1', $keys);
        $this->assertContains('context-gate:budget:day:alice:'.gmdate('Y-m-d'), $keys);
    }

    public function test_every_key_and_lock_starts_with_the_namespace(): void
    {
        $keys = $this->touchedBy(static function (Budget $budget): void {
            $budget->admit();
            $budget->handle(new Payload([['v' => 'a']]), static fn (Payload $p): Payload => $p);
        });

        $this->assertNotSame([], $keys);
        foreach ($keys as $key) {
            $this->assertStringStartsWith('context-gate:', $key);
        }
        $this->assertContains('context-gate:lock:rate:alice', $keys);
        $this->assertContains('context-gate:lock:budget:session:s1', $keys);
    }
}
