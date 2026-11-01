<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use ContextGate\Pipeline\Budget;
use ContextGate\Pipeline\Caps;
use ContextGate\Pipeline\Payload;
use ContextGate\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;

/** FR-022 / constitution VI: firewall.limits lowers a limit; a value above its code cap is ignored. */
class BudgetConfigTest extends TestCase
{
    public function test_a_configured_per_call_limit_below_the_default_takes_effect(): void
    {
        config(['context-gate.firewall.limits.per_call_bytes' => 100]);
        $rows = [['v' => str_repeat('a', 50)], ['v' => str_repeat('a', 50)]];

        $result = $this->budget()->handle(new Payload($rows), static fn (Payload $next): Payload => $next);

        $this->assertContains('budget.call', $result->flags);
        $this->assertCount(1, $result->rows);
        $this->assertLessThanOrEqual(100, $result->bytes);
    }

    public function test_a_configured_value_above_the_cap_is_ignored_and_the_profile_value_applies(): void
    {
        config(['context-gate.firewall.limits.per_call_bytes' => Caps::PER_CALL * 10]);

        $fits = $this->budget()->handle(new Payload([['v' => str_repeat('a', 16000)]]), static fn (Payload $next): Payload => $next);
        $over = $this->budget()->handle(new Payload([['v' => str_repeat('a', 17000)]]), static fn (Payload $next): Payload => $next);

        $this->assertNotContains('budget.call', $fits->flags);
        $this->assertContains('budget.call', $over->flags);
        $this->assertSame([], $over->rows);
    }

    public function test_a_configured_calls_per_minute_below_the_default_takes_effect(): void
    {
        config(['context-gate.firewall.limits.calls_per_minute' => 1]);
        $budget = $this->budget();

        $this->assertNull($budget->admit());
        $this->assertNotNull($budget->admit());
    }

    private function budget(): Budget
    {
        $cache = new Repository(new ArrayStore());

        return new Budget($cache, new RateLimiter($cache), 'alice', 'session-1', 3600);
    }
}
