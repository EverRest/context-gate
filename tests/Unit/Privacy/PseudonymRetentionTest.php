<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Privacy;

use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class PseudonymRetentionTest extends TestCase
{
    /** @var array<string, string> */
    private array $store = [];

    /** @var list<int> */
    private array $ttls = [];

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_saving_again_does_not_extend_a_day_key_past_its_first_expiry(): void
    {
        $this->fakeCache();
        Carbon::setTestNow(Carbon::parse('2026-10-31 00:00:00'));
        $pseudonymizer = new Pseudonymizer();

        $first = $pseudonymizer->pseudonym('cus', 'customers', '42', 'day:lookup', 'caller-a');

        Carbon::setTestNow(Carbon::parse('2026-10-31 23:00:00'));
        $this->assertSame($first, $pseudonymizer->pseudonym('cus', 'customers', '42', 'day:lookup', 'caller-a'));

        // The second save keeps the first absolute expiry: one hour is left, not a full day.
        $this->assertSame([86400, 3600], $this->ttls);

        Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:01'));
        $this->assertNull($pseudonymizer->resolve($first, 'day:lookup', 'caller-a'));
    }

    public function test_a_day_key_from_one_caller_does_not_resolve_for_another(): void
    {
        $this->fakeCache();
        $pseudonymizer = new Pseudonymizer();

        $issued = $pseudonymizer->pseudonym('cus', 'customers', '42', 'day:lookup', 'caller-a');

        $this->assertNull($pseudonymizer->resolve($issued, 'day:lookup', 'caller-b'));
        $this->assertSame(
            ['table' => 'customers', 'id' => '42'],
            $pseudonymizer->resolve($issued, 'day:lookup', 'caller-a'),
        );
    }

    public function test_a_day_scope_without_a_caller_is_refused(): void
    {
        $this->fakeCache();
        $this->expectException(InvalidArgumentException::class);

        (new Pseudonymizer())->pseudonym('cus', 'customers', '42', 'day:lookup');
    }

    private function fakeCache(): void
    {
        Cache::shouldReceive('get')->andReturnUsing(
            fn (string $key): ?string => $this->store[$key] ?? null,
        );
        Cache::shouldReceive('put')->andReturnUsing(
            function (string $key, string $value, int $seconds): bool {
                $this->store[$key] = $value;
                $this->ttls[] = $seconds;

                return true;
            },
        );
    }
}
