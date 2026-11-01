<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Http;

use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Server\Fingerprint;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Carbon;

/** FR-012, SC-005: a ceiling claimed in clientInfo never changes what the token sees. */
class ClientInfoCeilingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
    }

    public function test_a_client_claimed_higher_ceiling_does_not_change_the_tool_list(): void
    {
        $token = TokenFactory::create(['gate:read', 'gate:ceiling:public'], Carbon::now()->addDays(30));
        config(['context-gate.callers.token:'.$token->accessToken->getKey().'.tools' => ['echo']]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'echo' => ['enabled' => true, 'class' => EchoTool::class, 'gate' => 'echo.allow', 'fingerprint' => Fingerprint::of(new EchoTool())],
        ]]);

        $plain = $this->list($token->plainTextToken, ['name' => 't', 'version' => '1']);
        $claimed = $this->list($token->plainTextToken, ['name' => 't', 'version' => '1', 'ceiling' => 'personal', 'x-ceiling' => 'secret']);

        $this->assertNotEmpty($plain['result']['tools']);
        $this->assertSame($plain['result']['tools'], $claimed['result']['tools']);
    }

    /**
     * @param array<string, string> $clientInfo
     * @return array<string, mixed>
     */
    private function list(string $bearer, array $clientInfo): array
    {
        $this->withHeaders([
            'Authorization' => 'Bearer '.$bearer,
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ])->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => $clientInfo],
        ])->assertOk();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$bearer,
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ])->postJson('/'.config('context-gate.http.path'), ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);

        $response->assertOk();

        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
