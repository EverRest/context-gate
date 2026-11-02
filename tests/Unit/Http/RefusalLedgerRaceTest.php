<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Http;

use ContextGate\Http\RefusalLedger;
use ContextGate\Tests\Fakes\HookedStore;
use ContextGate\Tests\TestCase;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Spec 008 US2 #3 (T032): an increment that lands right after a refusal is counted; the ledger must not overwrite it. */
class RefusalLedgerRaceTest extends TestCase
{
    public function test_a_concurrent_increment_right_after_the_first_count_is_not_overwritten(): void
    {
        $store = new HookedStore();
        Cache::extend('race', fn () => new Repository($store));
        config(['cache.stores.race' => ['driver' => 'race'], 'context-gate.cache_store' => 'race']);
        config(['context-gate.http.refusals_per_minute' => 100]);
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');

        $request = Request::create('/mcp/gate', 'POST', [], [], [], ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_AUTHORIZATION' => 'Bearer 1|race']);
        $key = RefusalLedger::key($request);

        // Another worker created the counter (value 0, with its TTL) and has not counted yet: the first count of ours is 1.
        $store->put($key, 0, 60);
        // Right after our increment, a third worker counts too.
        $store->afterIncrement = static fn (HookedStore $s, string $k): int => $s->increment($k);

        RefusalLedger::record($request);

        $this->assertSame(2, (int) $store->get($key), 'both increments count: ours and the one that landed right after');
    }
}
