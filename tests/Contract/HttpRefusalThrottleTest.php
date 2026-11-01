<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Pipeline\Caps;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

/** Unauthenticated floods: refusals are counted per client and answered 429 past the limit, with no receipt and no lookup. */
class HttpRefusalThrottleTest extends TestCase
{
    private const LIMIT = 4;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        config([
            'context-gate.http.origins' => ['https://internal-tools.example.com'],
            'context-gate.http.refusals_per_minute' => self::LIMIT,
        ]);

        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
    }

    public function test_below_the_limit_refusals_behave_as_before(): void
    {
        for ($i = 0; $i < self::LIMIT - 1; $i++) {
            $this->call401()->assertStatus(401);
        }

        $this->assertCount(self::LIMIT - 1, $this->lines);
        $this->assertSame('token.missing', $this->receipt(0)['reason']);
    }

    public function test_past_the_limit_the_answer_is_429_with_one_throttle_receipt_and_no_lookup(): void
    {
        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->call401();
        }

        // limit refusals + the single throttle receipt written when the limit tripped
        $this->assertCount(self::LIMIT + 1, $this->lines);
        $this->assertSame('http.throttled', $this->receipt(self::LIMIT)['reason']);
        $this->assertSame('unknown', $this->receipt(self::LIMIT)['caller']);

        DB::enableQueryLog();

        $this->call401(origin: 'https://evil.example.com', bearer: '1|abc')->assertStatus(429)->assertHeader('Retry-After');
        $this->call401()->assertStatus(429)->assertJsonPath('error.code', -32001);

        $this->assertSame([], DB::getQueryLog());
        $this->assertCount(self::LIMIT + 1, $this->lines);
    }

    public function test_origin_refusal_skips_the_token_lookup_past_half_the_limit(): void
    {
        for ($i = 0; $i < self::LIMIT / 2; $i++) {
            $this->call401();
        }

        DB::enableQueryLog();
        $this->call401(origin: 'https://evil.example.com', bearer: '1|abc')->assertStatus(403);

        $this->assertSame([], DB::getQueryLog());
    }

    public function test_the_window_expires_and_refusals_are_counted_again(): void
    {
        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->call401();
        }

        $this->call401()->assertStatus(429);

        Carbon::setTestNow(Carbon::now()->addSeconds(61));

        $this->call401()->assertStatus(401);

        Carbon::setTestNow();
    }

    public function test_successful_requests_are_not_counted(): void
    {
        $bearer = TokenFactory::create(['gate:read', 'gate:ceiling:internal'])->plainTextToken;

        for ($i = 0; $i < self::LIMIT * 2; $i++) {
            $this->call401(bearer: $bearer)->assertStatus(200);
        }
    }

    public function test_two_clients_are_independent(): void
    {
        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->call401();
        }

        $this->call401()->assertStatus(429);
        $this->call401(ip: '10.9.9.9')->assertStatus(401);
    }

    public function test_the_ip_never_reaches_the_receipt_log(): void
    {
        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->call401(ip: '203.0.113.7');
        }

        $this->assertStringNotContainsString('203.0.113.7', implode("\n", $this->lines));
    }

    public function test_a_limit_above_its_cap_is_reported_by_gate_check(): void
    {
        config(['context-gate.http.refusals_per_minute' => Caps::REFUSALS_PER_MINUTE + 1]);

        $this->assertSame(1, Artisan::call('gate:check'));
        $this->assertStringContainsString('http.refusals_per_minute', Artisan::output());
    }

    /** @return TestResponse<Response> */
    private function call401(?string $origin = null, string $bearer = '', string $ip = '198.51.100.1'): TestResponse
    {
        $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];

        if ($bearer !== '') {
            $headers['Authorization'] = 'Bearer '.$bearer;
        }

        if ($origin !== null) {
            $headers['Origin'] = $origin;
        }

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders($headers)->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']],
        ]);
    }

    /** @return array<string, mixed> */
    private function receipt(int $index): array
    {
        return json_decode($this->lines[$index], true, 512, JSON_THROW_ON_ERROR);
    }
}
