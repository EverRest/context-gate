<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\HostUser;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Route;

/** Rule 2: a gate token is refused by the host application's auth:sanctum routes and accepted by the MCP route. */
class HostGuardRefusalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        config(['context-gate.http.origins' => []]);

        Route::get('/host/orders', static fn () => response()->json(['ok' => true]))->middleware('auth:sanctum');
    }

    public function test_host_route_behind_auth_sanctum_refuses_a_gate_token(): void
    {
        $token = TokenFactory::create(['gate:read', 'gate:ceiling:internal'])->plainTextToken;

        $this->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token])
            ->getJson('/host/orders')
            ->assertUnauthorized();
    }

    public function test_host_route_behind_auth_sanctum_still_accepts_a_host_user_token(): void
    {
        $token = HostUser::query()->create(['name' => 'Host Admin'])->createToken('host', ['*'])->plainTextToken;

        $this->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token])
            ->getJson('/host/orders')
            ->assertOk();
    }

    public function test_mcp_route_accepts_a_gate_token(): void
    {
        $token = TokenFactory::create(['gate:read', 'gate:ceiling:internal'])->plainTextToken;

        $this->withHeaders([
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']],
        ])->assertOk();
    }
}
