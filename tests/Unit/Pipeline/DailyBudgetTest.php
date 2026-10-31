<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use Carbon\CarbonImmutable;
use ContextGate\Pipeline\Budget;
use ContextGate\Pipeline\Payload;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use ContextGate\Tests\TestCase;

class DailyBudgetTest extends TestCase
{
    public function test_after_5_mb_in_one_utc_day_the_call_is_refused(): void
    {
        $cache = new Repository(new ArrayStore());
        $limiter = new RateLimiter($cache);

        // 80 calls of 65536 bytes make 5242880 bytes, exactly 5 MiB. Each call uses its own session,
        // because the session cap (2 MiB) would refuse first.
        for ($i = 0; $i < 80; $i++) {
            $budget = new Budget($cache, $limiter, 'alice', 'session-'.$i, 3600, perCall: 65536, perMinute: 300);
            $budget->handle($this->fullCall(65536), static fn (Payload $next): Payload => $next);
        }

        $budget = new Budget($cache, $limiter, 'alice', 'session-new', 3600, perCall: 65536, perMinute: 300);
        $refused = $budget->handle($this->fullCall(65536), static fn (Payload $next): Payload => $next);

        $this->assertContains('budget.daily', $refused->flags);
        $this->assertSame([], $refused->rows);
    }

    public function test_the_day_counter_ends_at_00_00_utc(): void
    {
        $this->assertSame(30, Budget::secondsUntilUtcMidnight(new CarbonImmutable('2026-10-31 23:59:30', 'UTC')));
        $this->assertSame(86400, Budget::secondsUntilUtcMidnight(new CarbonImmutable('2026-10-31 00:00:00', 'UTC')));
    }

    /** A response of exactly the given size in bytes. */
    private function fullCall(int $bytes): Payload
    {
        $pad = $bytes - strlen((string) json_encode([['v' => '']]));

        return new Payload([['v' => str_repeat('a', $pad)]]);
    }
}
