<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use ContextGate\Pipeline\Budget;
use ContextGate\Pipeline\Payload;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use ContextGate\Tests\TestCase;

class BudgetTest extends TestCase
{
    public function test_a_20_kb_output_is_truncated_at_a_record_boundary(): void
    {
        $rows = array_fill(0, 20, ['v' => str_repeat('a', 1000)]);

        $result = $this->budget()->handle(new Payload($rows), static fn (Payload $next): Payload => $next);

        $this->assertContains('budget.call', $result->flags);
        $this->assertNotEmpty($result->rows);
        $this->assertLessThan(20, count($result->rows));
        $this->assertSame(array_slice($rows, 0, count($result->rows)), $result->rows);
        $this->assertLessThanOrEqual(16384, strlen((string) json_encode($result->rows)));
    }

    public function test_the_default_per_call_limit_is_16384_bytes(): void
    {
        $pad = 16384 - strlen((string) json_encode([['v' => '']]));

        $exact = $this->budget()->handle(new Payload([['v' => str_repeat('a', $pad)]]), static fn (Payload $next): Payload => $next);
        $over = $this->budget()->handle(new Payload([['v' => str_repeat('a', $pad + 1)]]), static fn (Payload $next): Payload => $next);

        $this->assertNotContains('budget.call', $exact->flags);
        $this->assertSame(16384, $exact->bytes);
        $this->assertContains('budget.call', $over->flags);
        $this->assertSame([], $over->rows);
    }

    private function budget(): Budget
    {
        $cache = new Repository(new ArrayStore());

        return new Budget($cache, new RateLimiter($cache), 'alice', 'session-1', 3600);
    }
}
