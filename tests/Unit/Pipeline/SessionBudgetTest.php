<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use ContextGate\Pipeline\Budget;
use ContextGate\Pipeline\Payload;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

class SessionBudgetTest extends TestCase
{
    public function test_after_400_kb_in_one_session_the_next_call_is_refused(): void
    {
        $cache = new Repository(new ArrayStore());
        $budget = new Budget($cache, new RateLimiter($cache), 'alice', 'session-1', 3600);

        for ($i = 0; $i < 25; $i++) {
            $served = $budget->handle($this->fullCall(), static fn (Payload $next): Payload => $next);
            $this->assertNotEmpty($served->rows);
        }

        $refused = $budget->handle($this->fullCall(), static fn (Payload $next): Payload => $next);

        $this->assertContains('budget.session', $refused->flags);
        $this->assertSame([], $refused->rows);
    }

    public function test_another_session_of_the_same_caller_is_not_affected(): void
    {
        $cache = new Repository(new ArrayStore());
        $limiter = new RateLimiter($cache);
        $first = new Budget($cache, $limiter, 'alice', 'session-1', 3600);
        $second = new Budget($cache, $limiter, 'alice', 'session-2', 3600);

        for ($i = 0; $i < 25; $i++) {
            $first->handle($this->fullCall(), static fn (Payload $next): Payload => $next);
        }

        $result = $second->handle($this->fullCall(), static fn (Payload $next): Payload => $next);

        $this->assertNotContains('budget.session', $result->flags);
        $this->assertNotEmpty($result->rows);
    }

    /** A response of exactly the default per-call size, 16384 bytes. */
    private function fullCall(): Payload
    {
        $pad = 16384 - strlen((string) json_encode([['v' => '']]));

        return new Payload([['v' => str_repeat('a', $pad)]]);
    }
}
