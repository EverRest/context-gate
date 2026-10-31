<?php

declare(strict_types=1);

namespace ContextGate\Tests\EndToEnd;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Symfony\Component\Process\Process;

/** FR-013: HTTP serves a valid token in production; stdio stays refused there (spec 001). */
class HttpProductionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
    }

    public function test_a_valid_token_is_served_over_http_in_production(): void
    {
        app()['env'] = 'production';

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
        $this->assertSame('context-gate', json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['result']['serverInfo']['name']);
    }

    public function test_stdio_is_still_refused_in_production(): void
    {
        $process = new Process(
            [PHP_BINARY, 'vendor/bin/testbench', 'mcp:start', 'context-gate'],
            dirname(__DIR__, 2),
            ['APP_ENV' => 'production'],
        );
        $process->setInput('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}'."\n")
            ->setTimeout(30)->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertSame('', $process->getOutput(), 'stdout must stay empty');
    }
}
