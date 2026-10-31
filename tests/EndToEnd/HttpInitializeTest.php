<?php

declare(strict_types=1);

namespace ContextGate\Tests\EndToEnd;

use ContextGate\Server\GateServer;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;

class HttpInitializeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
    }

    public function test_initialize_with_a_valid_token_answers_with_server_name_and_version(): void
    {
        $token = TokenFactory::create(['gate:read', 'gate:ceiling:internal'])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ])->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']],
        ]);

        $response->assertOk();

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('context-gate', $body['result']['serverInfo']['name']);
        $this->assertSame(GateServer::VERSION, $body['result']['serverInfo']['version']);
    }
}
