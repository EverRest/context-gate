<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Http;

use ContextGate\Http\RefusalLedger;
use ContextGate\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Known gap (spec 008 Amendments): a credential counter that expires between add and increment is left without a TTL. */
class RefusalLedgerExpiryTest extends TestCase
{
    public function test_a_counter_that_expires_between_add_and_increment_still_has_a_ttl(): void
    {
        // Known gap, accepted by the owner (spec 008 Amendments, 2026-11-01): the re-put that would set the TTL overwrites
        // concurrent increments on Redis. It fails when run with CONTEXT_GATE_KNOWN_GAPS=1, by design, until an atomic TTL primitive exists.
        if (getenv('CONTEXT_GATE_KNOWN_GAPS') !== '1') {
            $this->markTestSkipped('Known gap: a counter created without TTL after an expiry between add and increment (spec 008 Amendments). Set CONTEXT_GATE_KNOWN_GAPS=1 to run it, and expect it to fail.');
        }

        $store = new class () extends ArrayStore {
            /** @var array<string, int> seconds of each key written with a TTL */
            public array $ttls = [];

            public function put($key, $value, $seconds)
            {
                $this->ttls[$key] = (int) $seconds;

                return parent::put($key, $value, $seconds);
            }

            // The key was there when add ran, and expired before increment: add reports it present, nothing is stored.
            public function add(string $key, mixed $value, int $seconds): bool
            {
                return false;
            }

            // Increments on a missing key create it without an expiry, as Redis INCRBY does.
            public function increment($key, $value = 1)
            {
                $total = (int) parent::get($key) + $value;
                parent::put($key, $total, 3600);
                unset($this->ttls[$key]);

                return $total;
            }
        };
        Cache::extend('expiring', fn () => new Repository($store));
        config(['cache.stores.expiring' => ['driver' => 'expiring'], 'context-gate.cache_store' => 'expiring']);
        config(['context-gate.http.refusals_per_minute' => 100]);
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');

        $request = Request::create('/mcp/gate', 'POST', [], [], [], ['REMOTE_ADDR' => '198.51.100.8', 'HTTP_AUTHORIZATION' => 'Bearer 1|expiry']);
        $key = RefusalLedger::key($request);

        RefusalLedger::record($request);

        $this->assertArrayHasKey($key, $store->ttls, 'the counter must have a TTL after the refusal is counted');
    }
}
