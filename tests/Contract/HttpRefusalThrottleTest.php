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

/** Unauthenticated floods: refusals are counted per (IP, credential) and answered 429 past the limit; a per-IP hard cap also answers 429 except to recently valid tokens. */
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

    public function test_garbage_from_a_shared_ip_never_blocks_a_token_that_authenticated_before(): void
    {
        $bearer = TokenFactory::create(['gate:read', 'gate:ceiling:internal'])->plainTextToken;
        $this->call401(bearer: $bearer)->assertStatus(200);

        for ($i = 0; $i < 200; $i++) {
            $this->call401(bearer: '1|garbage'.$i);
        }

        $before = count($this->lines);
        $this->call401(bearer: $bearer)->assertStatus(200);

        $added = implode("\n", array_slice($this->lines, $before));
        $this->assertStringNotContainsString('http.throttled', $added);
        $this->assertStringNotContainsString('token.', $added);
    }

    public function test_rotating_garbage_tokens_reach_the_per_ip_cap_and_then_get_429_with_one_summary_and_no_query(): void
    {
        $coarse = self::LIMIT * 10;

        for ($i = 0; $i < $coarse; $i++) {
            $this->call401(bearer: '1|g'.$i)->assertStatus(401);
        }

        $this->assertCount($coarse, $this->lines);

        DB::enableQueryLog();
        $this->call401(bearer: '1|next')->assertStatus(429)->assertHeader('Retry-After', '60')
            ->assertJsonPath('error.message', 'Too many refused requests.');
        $this->call401(origin: 'https://evil.example.com', bearer: '1|x')->assertStatus(429);
        $this->call401()->assertStatus(429);
        $this->assertSame([], DB::getQueryLog());

        for ($i = 0; $i < 5; $i++) {
            $this->call401(bearer: '1|y'.$i)->assertStatus(429);
        }

        $this->assertCount($coarse + 1, $this->lines);
        $this->assertSame('http.throttled', $this->receipt($coarse)['reason']);
        $this->assertSame('unknown', $this->receipt($coarse)['caller']);
    }

    public function test_a_flooded_ip_blocks_a_valid_token_that_did_not_authenticate_recently_until_the_window_ends(): void
    {
        $marked = TokenFactory::create(['gate:read', 'gate:ceiling:internal'])->plainTextToken;
        $idle = TokenFactory::create(['gate:read', 'gate:ceiling:internal'])->plainTextToken;
        $this->call401(bearer: $marked)->assertStatus(200);
        $this->flood();

        $this->call401(bearer: $marked)->assertStatus(200);
        $this->call401(bearer: $idle)->assertStatus(429);

        Carbon::setTestNow(Carbon::now()->addSeconds(61));

        $this->call401(bearer: $idle)->assertStatus(200);

        Carbon::setTestNow();
    }

    public function test_the_marker_expires_after_ten_minutes(): void
    {
        $bearer = TokenFactory::create(['gate:read', 'gate:ceiling:internal'])->plainTextToken;
        $this->call401(bearer: $bearer)->assertStatus(200);

        Carbon::setTestNow(Carbon::now()->addSeconds(599));
        $this->flood();
        $this->call401(bearer: $bearer)->assertStatus(200); // a success renews the marker

        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $this->flood();
        $this->call401(bearer: $bearer)->assertStatus(200);

        Carbon::setTestNow(Carbon::now()->addSeconds(601));
        $this->flood();
        $this->call401(bearer: $bearer)->assertStatus(429);

        Carbon::setTestNow();
    }

    public function test_a_marked_token_that_is_revoked_still_gets_its_normal_401(): void
    {
        $new = TokenFactory::create(['gate:read', 'gate:ceiling:internal']);
        $this->call401(bearer: $new->plainTextToken)->assertStatus(200);
        $this->flood();

        $new->accessToken->delete();

        $this->call401(bearer: $new->plainTextToken)->assertStatus(401);
    }

    public function test_a_marked_token_that_expired_still_gets_its_normal_401(): void
    {
        $bearer = TokenFactory::create(['gate:read', 'gate:ceiling:internal'], Carbon::now()->addDay())->plainTextToken;
        $this->call401(bearer: $bearer)->assertStatus(200);
        $this->flood();

        Carbon::setTestNow(Carbon::now()->addDays(2));

        $this->call401(bearer: $bearer)->assertStatus(401);

        Carbon::setTestNow();
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

    public function test_the_marker_stores_only_a_hash_prefix(): void
    {
        $bearer = TokenFactory::create(['gate:read', 'gate:ceiling:internal'])->plainTextToken;
        $this->call401(bearer: $bearer)->assertStatus(200);

        $store = GateCache::store();
        $this->assertNotNull($store->get('gate:valid:'.substr(hash('sha256', $bearer), 0, 16)));

        $stored = serialize((new \ReflectionProperty($store->getStore(), 'storage'))->getValue($store->getStore()));
        $this->assertStringNotContainsString($bearer, $stored);
        $this->assertStringNotContainsString(explode('|', $bearer)[1], $stored);
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
