<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\HostUser;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use ContextGate\Token\GateAccessToken;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Carbon;

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
        $token = TokenFactory::create(['gate:read', 'gate:ceiling:internal'], Carbon::now()->addDays(30))->plainTextToken;

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
        $token = TokenFactory::create(['gate:read', 'gate:ceiling:internal'], Carbon::now()->addDays(30))->plainTextToken;

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

    public function test_mcp_route_refuses_a_host_user_token_even_when_its_id_matches_a_gate_token(): void
    {
        // The host token takes id 1 in personal_access_tokens; the gate holder's token also takes id 1 in gate_access_tokens.
        $host = HostUser::query()->create(['name' => 'Host Admin'])->createToken('host', ['*'])->plainTextToken;
        $gate = TokenFactory::create(['gate:read', 'gate:ceiling:internal'], Carbon::now()->addDays(30));

        $this->assertSame(1, (int) $gate->accessToken->getKey());
        $this->assertStringStartsWith('1|', $host);

        $this->assertMcpStatus($host, 401);
    }

    public function test_mcp_route_refuses_a_gate_access_token_owned_by_a_host_user(): void
    {
        $host = HostUser::query()->create(['name' => 'Host Admin']);
        $plain = Str::random(40);

        $row = GateAccessToken::query()->forceCreate([
            'tokenable_type' => HostUser::class,
            'tokenable_id' => $host->getKey(),
            'name' => 'host',
            'token' => hash('sha256', $plain),
            'abilities' => ['gate:read', 'gate:ceiling:internal'],
            'expires_at' => now()->addDays(30),
        ]);

        $this->assertMcpStatus($row->getKey().'|'.$plain, 401);
    }

    public function test_sanctum_personal_access_token_model_is_unchanged_after_boot(): void
    {
        $this->assertSame(PersonalAccessToken::class, Sanctum::$personalAccessTokenModel);
    }

    private function assertMcpStatus(string $bearer, int $status): void
    {
        $this->withHeaders([
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
            'Authorization' => 'Bearer '.$bearer,
        ])->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']],
        ])->assertStatus($status);
    }
}
