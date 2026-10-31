<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/** User Story 2 scenario 3, SC-004: a token revoked between two requests is refused on the second. */
class RevokedImmediateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        config(['context-gate.http.origins' => []]);
    }

    public function test_token_revoked_between_two_requests_is_refused_on_the_second(): void
    {
        $token = TokenFactory::create(['gate:read', 'gate:ceiling:internal']);

        $this->initialize($token->plainTextToken)->assertStatus(200);

        $token->accessToken->delete();

        // RequestGuard caches the resolved user for the app instance; a new HTTP request gets a new app, so forget it here.
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
