<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Pipeline\Budget;
use ContextGate\Pipeline\Payload;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use ContextGate\Tests\TestCase;

class RateLimitTest extends TestCase
{
    public function test_60_calls_pass_and_the_61st_is_rate_limited_with_a_retry_time(): void
    {
        $cache = new Repository(new ArrayStore());
        $budget = new Budget($cache, new RateLimiter($cache), 'alice', 'session-1', 3600);
        $next = static fn (Payload $payload): Payload => $payload;

        $admitted = [];

        for ($i = 0; $i < 61; $i++) {
            $admitted[] = $budget->admit();
        }

        $this->assertSame(array_fill(0, 60, null), array_slice($admitted, 0, 60));
        $this->assertIsInt($admitted[60]);
        $this->assertGreaterThan(0, $admitted[60]);
        $this->assertLessThanOrEqual(60, $admitted[60]);
        $this->assertNotEmpty($budget->handle(new Payload([['a' => 1]]), $next)->rows);
    }
}
