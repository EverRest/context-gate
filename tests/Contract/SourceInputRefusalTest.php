<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\GateServer;
use ContextGate\Server\ReasonCode;
use ContextGate\Server\Tool;
use ContextGate\Source\SourceFailure;
use ContextGate\Source\SourceResult;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Transport\FakeTransporter;

/** F9: a source refusal with input.invalid returns the same JSON-RPC error as other input.invalid, not 'Request failed'. */
class SourceInputRefusalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('source.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['refuse']]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'refuse' => [
                'enabled' => true,
                'class' => RefusingSourceTool::class,
                'gate' => 'source.allow',
                'fingerprint' => hash('sha256', (new RefusingSourceTool())->description()),
            ],
        ]]);
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info', 'error')->andReturnNull();
    }

    public function test_a_source_input_refusal_returns_the_input_invalid_json_rpc_error(): void
    {
        $raw = $this->send((string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => ['name' => 'refuse', 'arguments' => ['prefix' => 'x']],
        ], JSON_THROW_ON_ERROR))[0];

        $response = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('result', $response);
        $this->assertSame(-32602, $response['error']['code']);
        $this->assertSame(7, $response['id']);
        $this->assertStringNotContainsString('Request failed', $raw);
    }

    /** @return list<string> */
    private function send(string ...$messages): array
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

        foreach ($messages as $message) {
            $server->handle($message);
        }

        return $transport->sent;
    }
}

final class RefusingSourceTool extends Tool
{
    public function name(): string
    {
        return 'refuse';
    }

    public function description(): string
    {
        return 'Refuses its input the way a source does.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['prefix' => ['type' => 'string']]];
    }

    public function handle(array $arguments): Response
    {
        throw SourceFailure::from(SourceResult::failed(ReasonCode::InputInvalid));
    }
}
