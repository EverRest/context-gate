<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use ContextGate\Pipeline\Budget;
use ContextGate\Pipeline\Payload;
use ContextGate\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;

class BudgetCounterTtlTest extends TestCase
{
    public function test_a_counter_that_expires_between_add_and_increment_still_gets_its_ttl(): void
    {
        $cache = $this->dropAfterAdd();
        $budget = new Budget($cache, new RateLimiter($cache), 'alice', 's1', 60, perCall: 500);

        $budget->handle(new Payload([['v' => 'abc']]), static fn (Payload $p): Payload => $p);
        $this->assertGreaterThan(0, $cache->get('budget:session:s1'));

        $this->travel(61)->seconds();

        $this->assertNull($cache->get('budget:session:s1'), 'the counter must expire, not live forever');
    }

    public function test_a_rollback_after_expiry_never_leaves_a_negative_counter_without_ttl(): void
    {
        $cache = $this->dropAfterAdd();
        $budget = new Budget($cache, new RateLimiter($cache), 'alice', 's2', 60, perCall: 500, perSession: 1);

        $result = $budget->handle(new Payload([['v' => 'abc']]), static fn (Payload $p): Payload => $p);

        $this->assertContains('budget.session', $result->flags);
        $this->assertGreaterThanOrEqual(0, (int) $cache->get('budget:session:s2'));

        $this->travel(61)->seconds();

        $this->assertNull($cache->get('budget:session:s2'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{list<array<string, mixed>>, bool}
     */
    private function oldTruncate(array $rows, int $limit): array
    {
        $kept = [];

        foreach ($rows as $row) {
            $kept[] = $row;

            if (strlen(json_encode($kept, JSON_THROW_ON_ERROR)) > $limit) {
                array_pop($kept);

                return [$kept, true];
            }
        }

        return [$kept, false];
    }

    public function test_fit_matches_the_old_per_row_reencoding_algorithm(): void
    {
        $rowSets = [
            [],
            [['a' => 1]],
            [['a' => 1], ['a' => 'é€😀'], ['a' => str_repeat('x', 50)], []],
            array_fill(0, 40, ['id' => 7, 'n' => 'name "quoted" / slash']),
        ];

        foreach ($rowSets as $rows) {
            foreach ([0, 1, 2, 3, 10, 11, 12, 13, 50, 99, 100, 101, 400, 100000] as $limit) {
                $cache = new Repository(new ArrayStore());
                $budget = new Budget($cache, new RateLimiter($cache), 'alice', 's', 3600, perCall: $limit);

                $this->assertSame($this->oldTruncate($rows, $limit), $budget->fit($rows), 'limit '.$limit.' rows '.count($rows));
            }
        }
    }

    /** A cache where the key vanishes right after `add` found it, as when it expires between add and increment. */
    private function dropAfterAdd(): Repository
    {
        return new class (new ArrayStore()) extends Repository {
            /** @param \UnitEnum|array<mixed>|string $key */
            public function add($key, $value, $ttl = null): bool
            {
                parent::add($key, $value, $ttl);
                $this->forget($key); // `add` saw the key, which then expired

                return false;
            }
        };
    }
}
