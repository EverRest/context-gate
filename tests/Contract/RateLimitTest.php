<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Pipeline\Budget;
use ContextGate\Pipeline\Payload;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

class RateLimitTest extends TestCase
{
    public function test_60_calls_pass_and_the_61st_is_rate_limited_with_a_retry_time(): void
    {
        $cache = new Repository(new ArrayStore());
        $budget = new Budget($cache, new RateLimiter($cache), 'alice', 'session-1', 3600);
        $next = static fn (Payload $payload): Payload => $payload;

        for ($i = 0; $i < 60; $i++) {
            $served = $budget->handle(new Payload([['a' => 1]]), $next);
            $this->assertNotEmpty($served->rows);
        }

        $refused = $budget->handle(new Payload([['a' => 1]]), $next);

        $this->assertContains('rate.limited', $refused->flags);
        $this->assertSame([], $refused->rows);
        $this->assertNotNull($budget->retryAfter);
        $this->assertGreaterThan(0, $budget->retryAfter);
        $this->assertLessThanOrEqual(60, $budget->retryAfter);
    }
}
