<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/** Edge cases of spec 005: a token that expires during an open session (FR-015), and the clock is compared in UTC. */
class TokenEdgeCasesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        config(['context-gate.http.origins' => []]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set('UTC');

        parent::tearDown();
    }

    public function test_token_that_expires_during_an_open_session_is_refused_at_the_next_request(): void
    {
        $token = TokenFactory::create(['gate:read', 'gate:ceiling:internal'], Carbon::now('UTC')->addMinutes(10));

        $this->initialize($token->plainTextToken)->assertStatus(200);

        Carbon::setTestNow(Carbon::now('UTC')->addMinutes(11));

        // RequestGuard caches the resolved user for the app instance; a new HTTP request gets a new app, so forget it here.
        Auth::forgetGuards();

        $this->initialize($token->plainTextToken)->assertStatus(401);
    }

    public function test_expiry_is_compared_in_utc_when_the_app_runs_in_another_timezone(): void
    {
        // UTC+14: a wall-clock comparison that ignored the zone would be off by many hours here.
        config(['app.timezone' => 'Pacific/Kiritimati']);
        date_default_timezone_set('Pacific/Kiritimati');

        $token = TokenFactory::create(['gate:read', 'gate:ceiling:internal'], Carbon::now('UTC')->addMinutes(10));

        Carbon::setTestNow(Carbon::now('UTC')->addMinutes(5));
        $this->initialize($token->plainTextToken)->assertStatus(200);

        Carbon::setTestNow(Carbon::now('UTC')->addMinutes(11));
        Auth::forgetGuards();
        $this->initialize($token->plainTextToken)->assertStatus(401);
    }

    public function test_expiry_is_compared_in_utc_when_the_app_runs_west_of_utc(): void
    {
        config(['app.timezone' => 'America/Los_Angeles']);
        date_default_timezone_set('America/Los_Angeles');

        $token = TokenFactory::create(['gate:read', 'gate:ceiling:internal'], Carbon::now('UTC')->subMinutes(10));

        // A zone-blind read would place the expiry hours later than intended, so the token would still pass.
        Auth::forgetGuards();
        $this->initialize($token->plainTextToken)->assertStatus(401);
    }

    /** @return TestResponse<Response> */
    private function initialize(string $bearer): TestResponse
    {
        return $this->withHeaders([
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
            'Authorization' => 'Bearer '.$bearer,
        ])->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']],
        ]);
    }
}
