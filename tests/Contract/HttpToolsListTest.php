<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\GateServer;
use ContextGate\Server\Fingerprint;
use ContextGate\Server\Tool;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\Fixtures\HiddenTool;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Laravel\Mcp\Server\Transport\FakeTransporter;

/** FR-001: the HTTP tool list equals the stdio tool list for the same caller. */
class HttpToolsListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
    }

    public function test_http_tool_list_equals_the_stdio_tool_list_for_the_same_caller(): void
    {
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'echo' => $this->tool(EchoTool::class),
            'secret' => $this->tool(HiddenTool::class),
        ]]);

        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['echo']]);
        $stdio = $this->stdioList();

        $token = TokenFactory::create(['gate:read', 'gate:ceiling:internal']);
        config(['context-gate.callers.token:'.$token->accessToken->getKey().'.tools' => ['echo']]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ])->postJson('/'.config('context-gate.http.path'), ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);

        $response->assertOk();
        $http = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($stdio['result']['tools']);
        $this->assertSame($stdio['result']['tools'], $http['result']['tools']);
    }

    /**
     * @param class-string<Tool> $class
     * @return array<string, mixed>
     */
    private function tool(string $class): array
    {
        return [
            'enabled' => true,
            'class' => $class,
            'gate' => 'echo.allow',
            'fingerprint' => Fingerprint::of(new $class()),
        ];
    }

    /** @return array<string, mixed> */
    private function stdioList(): array
    {
        $transport = new class () extends FakeTransporter {
            /** @var list<string> */
            public array $sent = [];

            public function send(string $message): void
            {
                $this->sent[] = $message;
            }
        };

        $server = new GateServer($transport);
        $server->start();
        $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], JSON_THROW_ON_ERROR));

        return json_decode($transport->sent[0], true, 512, JSON_THROW_ON_ERROR);
    }
}
