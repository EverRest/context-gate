<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Http;

use ContextGate\Http\RefusalLedger;
use ContextGate\Server\ReasonCode;
use ContextGate\Tests\Fakes\RecordingStore;
use ContextGate\Tests\TestCase;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Spec 008 US5 (T023): the refusal ledger's counters, trip marker and summary are under the package namespace. */
class RefusalLedgerKeysTest extends TestCase
{
    public function test_every_refusal_key_is_under_the_namespace(): void
    {
        $store = new RecordingStore();
        Cache::extend('recording', fn () => new Repository($store));
        config(['cache.stores.recording' => ['driver' => 'recording'], 'context-gate.cache_store' => 'recording']);
        config(['context-gate.http.refusals_per_minute' => 1]);
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');

        $request = Request::create('/mcp/gate', 'POST', [], [], [], ['REMOTE_ADDR' => '198.51.100.1', 'HTTP_AUTHORIZATION' => 'Bearer 1|abc']);

        // One refusal trips the credential key (limit 1); ten refusals from one IP reach the coarse threshold.
        for ($i = 0; $i < 10; $i++) {
            RefusalLedger::record($request);
        }
        RefusalLedger::receipt($request, 'unknown', ReasonCode::TokenInvalid);

        $touched = array_values(array_unique($store->touched));
        $this->assertNotSame([], $touched);
        $this->assertSame([], preg_grep('/^(gate|budget):/', $touched), 'no key keeps an old prefix');

        $this->assertNotSame([], preg_grep('/^context-gate:refusals:cred:198\.51\.100\.1:[0-9a-f]{16}$/', $touched));
        $this->assertContains('context-gate:refusals:ip:198.51.100.1', $touched);
        $this->assertContains('context-gate:refusals:summary:198.51.100.1', $touched);
        $this->assertNotSame([], preg_grep('/^context-gate:refusals:tripped:198\.51\.100\.1:[0-9a-f]{16}$/', $touched));
    }
}
