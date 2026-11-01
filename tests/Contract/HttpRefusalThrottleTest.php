<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Pipeline\Caps;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use ContextGate\Http\RefusalLedger;
use ContextGate\Support\GateCache;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

/** Unauthenticated floods: refusals are counted per (IP, credential) and answered 429 past the limit; a coarse per-IP counter only bounds log volume and never denies service. */
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

    public function test_the_same_bad_token_past_the_limit_gets_429_with_one_receipt_and_no_query(): void
    {
        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->call401(bearer: '1|bad');
        }

        // limit refusals + the single throttle receipt written when the limit tripped
        $this->assertCount(self::LIMIT + 1, $this->lines);
        $this->assertSame('http.throttled', $this->receipt(self::LIMIT)['reason']);
        $this->assertSame('unknown', $this->receipt(self::LIMIT)['caller']);

        DB::enableQueryLog();

        $this->call401(origin: 'https://evil.example.com', bearer: '1|bad')->assertStatus(429)->assertHeader('Retry-After', '60');
        $this->call401(bearer: '1|bad')->assertStatus(429)
            ->assertJsonPath('error.code', -32029)
            ->assertJsonPath('error.message', 'Too many refused requests.');

        $this->assertSame([], DB::getQueryLog());
        $this->assertCount(self::LIMIT + 1, $this->lines);
    }

    public function test_no_token_is_its_own_counter(): void
    {
        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->call401();
        }

        $this->call401()->assertStatus(429);
        $this->call401(bearer: '1|other')->assertStatus(401);
    }

    public function test_rotating_garbage_tokens_from_one_ip_never_get_429_and_log_only_one_summary(): void
    {
        $coarse = self::LIMIT * 10;

        for ($i = 0; $i < $coarse + 20; $i++) {
            $this->call401(bearer: '1|g'.$i)->assertStatus(401);
        }

        $this->call401()->assertStatus(401);
        $this->call401(origin: 'https://evil.example.com', bearer: '1|x')->assertStatus(403);

        // coarse refusals, then exactly one summary; everything above the threshold is unrecorded
        $this->assertCount($coarse + 1, $this->lines);
        $this->assertSame('http.throttled', $this->receipt($coarse)['reason']);
        $this->assertSame('unknown', $this->receipt($coarse)['caller']);
    }

    public function test_a_valid_token_from_a_flooded_ip_still_gets_200(): void
    {
        $bearer = TokenFactory::create(['gate:read', 'gate:ceiling:internal'])->plainTextToken;
        $this->flood();

        $this->assertGreaterThanOrEqual(self::LIMIT * 10, (int) GateCache::store()->get('gate:refusals-ip:198.51.100.1'));
        $this->call401(bearer: $bearer)->assertStatus(200);
        $this->call401(bearer: $bearer)->assertStatus(200);
    }

    public function test_a_known_token_refusal_is_recorded_from_a_flooded_ip_and_an_anonymous_one_is_not(): void
    {
        $new = TokenFactory::create(['gate:ceiling:internal']);
        $this->call401(bearer: $new->plainTextToken)->assertStatus(403);
        $this->flood();

        $before = count($this->lines);
        $this->call401(bearer: $new->plainTextToken)->assertStatus(403);
        $this->call401(origin: 'https://evil.example.com', bearer: $new->plainTextToken)->assertStatus(403);

        $added = array_map(fn (int $i): array => $this->receipt($i), range($before, count($this->lines) - 1));
        $this->assertSame(['token.no_read', 'origin.denied'], array_column($added, 'reason'));
        $this->assertSame(['token:'.$new->accessToken->getKey()], array_values(array_unique(array_column($added, 'caller'))));
    }

    public function test_the_attribution_lookup_runs_for_a_well_formed_bearer_and_is_skipped_otherwise(): void
    {
        DB::enableQueryLog();
        $this->call401(origin: 'https://evil.example.com', bearer: '1|bad')->assertStatus(403);
        $this->call401(origin: 'https://evil.example.com')->assertStatus(403);
        $this->assertSame([], DB::getQueryLog());

        $this->call401(origin: 'https://evil.example.com', bearer: '1|'.str_repeat('a', 40))->assertStatus(403);
        $this->assertNotSame([], DB::getQueryLog());

        $this->assertTrue(RefusalLedger::looksLikeToken('12|'.str_repeat('aZ09', 12)));
        $this->assertFalse(RefusalLedger::looksLikeToken('x|'.str_repeat('a', 40)));
        $this->assertFalse(RefusalLedger::looksLikeToken('1|'.str_repeat('a', 39)));
        $this->assertFalse(RefusalLedger::looksLikeToken(str_repeat('a', 48)));
    }

    public function test_cache_values_hold_no_ip_and_no_token_hash(): void
    {
        $this->call401(bearer: '1|bad', ip: '203.0.113.7');
        $this->flood();

        $store = GateCache::store()->getStore();
        $values = serialize(array_values(array_map(static fn (array $e): mixed => $e['value'], (new \ReflectionProperty($store, 'storage'))->getValue($store))));

        $this->assertStringNotContainsString('203.0.113.7', $values);
        $this->assertStringNotContainsString(substr(hash('sha256', '1|bad'), 0, 16), $values);
    }

    private function flood(): void
    {
        for ($i = 0; $i < self::LIMIT * 10; $i++) {
            $this->call401(bearer: '1|f'.$i);
        }
    }

    public function test_the_window_expires_and_refusals_are_counted_again(): void
    {
        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->call401(bearer: '1|bad');
        }

        $this->call401(bearer: '1|bad')->assertStatus(429);

        Carbon::setTestNow(Carbon::now()->addSeconds(61));

        $this->call401(bearer: '1|bad')->assertStatus(401);

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

    public function test_the_key_hashes_at_most_512_bytes_of_the_bearer(): void
    {
        $key = static fn (string $bearer): string => RefusalLedger::key(
            Request::create('/', 'POST', server: ['REMOTE_ADDR' => '198.51.100.1', 'HTTP_AUTHORIZATION' => 'Bearer '.$bearer]),
        );

        $this->assertSame($key(str_repeat('a', 512).'x'), $key(str_repeat('a', 10000)));
        $this->assertLessThan(80, strlen($key(str_repeat('a', 10000))));
        $this->assertStringEndsWith(':none', RefusalLedger::key(Request::create('/', 'POST', server: ['REMOTE_ADDR' => '198.51.100.1'])));
    }

    public function test_neither_the_ip_nor_the_token_hash_reaches_the_receipt_log(): void
    {
        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->call401(bearer: '1|bad', ip: '203.0.113.7');
        }

        $hash = substr(hash('sha256', '1|bad'), 0, 16);
        $log = implode("\n", $this->lines);

        $this->assertStringContainsString('http.throttled', $log);
        $this->assertStringNotContainsString('203.0.113.7', $log);
        $this->assertStringNotContainsString($hash, $log);
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
        Auth::forgetGuards(); // the test app keeps one guard across requests; a real request starts clean
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
