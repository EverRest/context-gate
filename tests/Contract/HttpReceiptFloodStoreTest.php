<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fakes\FlakyStore;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Review finding (spec 008): the flood check that decides whether an anonymous refusal is receipted can fail while the
 * refusal itself is answered. The refusal keeps its own status, and its receipt is still written.
 */
class HttpReceiptFloodStoreTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        config([
            'context-gate.http.origins' => ['https://internal-tools.example.com'],
            'context-gate.http.refusals_per_minute' => 4,
        ]);
        Cache::extend('flaky', fn () => new Repository(new FlakyStore()));
        config(['cache.stores.flaky' => ['driver' => 'flaky'], 'context-gate.cache_store' => 'flaky']);

        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
    }

    protected function tearDown(): void
    {
        FlakyStore::reset();

        parent::tearDown();
    }

    public function test_a_flood_check_that_fails_keeps_the_refusal_status_and_writes_its_receipt(): void
    {
        // Only the per-IP counter read fails: the throttle still counts, and the refusal is answered as usual.
        FlakyStore::$down = true;
        FlakyStore::$only = 'refusals:ip';
        FlakyStore::$methods = ['get'];

        $this->sendMcp()->assertStatus(401);

        // record() also reads the IP key (its add), so it receipts its own store failure: that one is expected.
        $reasons = array_map(static fn (string $line): string => json_decode($line, true, 512, JSON_THROW_ON_ERROR)['reason'], array_values(array_filter($this->lines, static fn (string $line): bool => str_contains($line, '"decision"'))));
        $this->assertSame(1, count(array_keys($reasons, 'token.missing', true)), 'the refusal is receipted once');
        $this->assertSame(['token.missing', 'store.unavailable'], array_values(array_unique($reasons)), 'only the refusal and the store failure are receipted');
    }

    /** @return TestResponse<Response> */
    private function sendMcp(?string $bearer = null): TestResponse
    {
        Auth::forgetGuards();
        $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];

        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer '.$bearer;
        }

        return $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2'])->withHeaders($headers)->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']],
        ]);
    }
}
