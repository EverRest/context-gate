<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fakes\FlakyStore;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Cache\Repository;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

/**
 * Spec 008 US4 (T019): with the store down, an HTTP request is refused before any token work; a refusal whose count
 * cannot be recorded is still answered with its own status, and the store failure is receipted.
 */
class HttpStoreUnavailableTest extends TestCase
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

    public function test_a_store_that_is_down_answers_503_before_any_token_work(): void
    {
        FlakyStore::$down = true;
        DB::enableQueryLog();

        $this->sendMcp(bearer: '1|'.str_repeat('a', 40))
            ->assertStatus(503)
            ->assertExactJson(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'Service unavailable.']]);

        $this->assertSame([], DB::getQueryLog(), 'the token is not looked up');
        $receipts = array_values(array_filter($this->lines, static fn (string $line): bool => str_contains($line, '"decision"')));
        $this->assertCount(1, $receipts);
        $receipt = json_decode($receipts[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('store.unavailable', $receipt['reason']);
        $this->assertSame('unknown', $receipt['caller']);
        $this->assertStringNotContainsString('198.51.100.1', implode("\n", $this->lines));
        $this->assertStringNotContainsString('FAKE_FIXTURE_ONLY', implode("\n", $this->lines));
    }

    public function test_a_refusal_whose_count_cannot_be_recorded_keeps_its_own_status_and_is_receipted(): void
    {
        // Reads work (the throttle can check), increments fail (the refusal cannot be counted).
        FlakyStore::$down = true;
        FlakyStore::$methods = ['increment'];

        $this->sendMcp()->assertStatus(401);

        $reasons = array_map(static fn (string $line): ?string => json_decode($line, true, 512, JSON_THROW_ON_ERROR)['reason'] ?? null, $this->lines);
        $this->assertContains('store.unavailable', $reasons);
        $this->assertContains('token.missing', $reasons);
    }

    /**
     * A POST as an MCP client would send it, from a fixed client address.
     *
     * @return TestResponse<Response>
     */
    private function sendMcp(?string $bearer = null): TestResponse
    {
        Auth::forgetGuards();
        $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];

        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer '.$bearer;
        }

        return $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->withHeaders($headers)->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']],
        ]);
    }
}
