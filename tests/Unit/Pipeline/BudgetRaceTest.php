<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use ContextGate\Pipeline\Budget;
use ContextGate\Pipeline\Payload;
use ContextGate\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;

class BudgetRaceTest extends TestCase
{
    public function test_a_competing_reservation_between_check_and_spend_cannot_overshoot_the_session_limit(): void
    {
        $cache = $this->racingCache('budget:session:s1', 700);
        $budget = new Budget($cache, new RateLimiter($cache), 'alice', 's1', 3600, perCall: 500, perSession: 1000);

        $result = $budget->handle($this->payloadOf(400), static fn (Payload $p): Payload => $p);

        $this->assertContains('budget.session', $result->flags);
        $this->assertSame([], $result->rows);
        $this->assertSame(700, $cache->get('budget:session:s1'), 'the refused reservation is rolled back');
    }

    public function test_a_daily_refusal_also_rolls_back_the_session_reservation(): void
    {
        $day = 'budget:day:alice:'.gmdate('Y-m-d');
        $cache = $this->racingCache($day, 4_999_900);
        $budget = new Budget($cache, new RateLimiter($cache), 'alice', 's2', 3600, perCall: 500, perDay: 5_000_000);

        $result = $budget->handle($this->payloadOf(400), static fn (Payload $p): Payload => $p);

        $this->assertContains('budget.daily', $result->flags);
        $this->assertSame(0, $cache->get('budget:session:s2'));
        $this->assertSame(4_999_900, $cache->get($day));
    }

    public function test_the_counter_ends_exactly_at_the_limit_after_sequential_reservations(): void
    {
        $cache = new Repository(new ArrayStore());
        $budget = new Budget($cache, new RateLimiter($cache), 'alice', 's3', 3600, perCall: 500, perSession: 1000);
        $size = strlen((string) json_encode([['v' => str_repeat('a', 400)]]));
        $served = 0;

        for ($i = 0; $i < 5; $i++) {
            $served += $budget->handle($this->payloadOf(400), static fn (Payload $p): Payload => $p)->rows === [] ? 0 : 1;
        }

        $this->assertSame(2, $served);
        $this->assertSame(2 * $size, $cache->get('budget:session:s3'));
    }

    public function test_a_competing_hit_cannot_both_pass_at_limit_minus_one(): void
    {
        $cache = new Repository(new ArrayStore());
        $limiter = new class ($cache) extends RateLimiter {
            public function hit($key, $decaySeconds = 60)
            {
                parent::hit($key, $decaySeconds); // the competing call

                return parent::hit($key, $decaySeconds);
            }
        };
        $budget = new Budget($cache, $limiter, 'alice', 's4', 3600, perMinute: 1);

        $this->assertGreaterThan(0, $budget->admit());
    }

    /** A cache where another call adds $bytes to $key right after our call's `add` and before its `increment`. */
    private function racingCache(string $key, int $bytes): Repository
    {
        return new class (new ArrayStore(), $key, $bytes) extends Repository {
            private bool $raced = false;

            public function __construct(ArrayStore $store, private readonly string $raceKey, private readonly int $raceBytes)
            {
                parent::__construct($store);
            }

            /** @param \UnitEnum|array<mixed>|string $key */
            public function add($key, $value, $ttl = null): bool
            {
                $added = parent::add($key, $value, $ttl);

                if ($key === $this->raceKey && ! $this->raced) {
                    $this->raced = true;
                    parent::add($key, 0, 3600);
                    parent::increment($key, $this->raceBytes);
                }

                return $added;
            }
        };
    }

    private function payloadOf(int $bytes): Payload
    {
        return new Payload([['v' => str_repeat('a', $bytes)]]);
    }
}
