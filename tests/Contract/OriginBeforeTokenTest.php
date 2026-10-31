<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;

class OriginBeforeTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        config(['context-gate.http.origins' => ['https://internal-tools.example.com']]);
    }

    public function test_foreign_origin_without_a_token_is_refused_with_403_not_401(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
            'Origin' => 'https://evil.example.com',
        ])->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']],
        ]);

        $response->assertStatus(403);
    }
}
