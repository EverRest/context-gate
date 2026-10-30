<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\GateServer;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\Fixtures\HiddenTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\Transport\FakeTransporter;

class ReceiptPerCallTest extends TestCase
{
    /** @var list<string> */
    private array $receipts = [];

    public function test_every_call_writes_exactly_one_receipt(): void
    {
        Gate::define('echo.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['echo']]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'echo' => ['enabled' => true, 'class' => EchoTool::class, 'gate' => 'echo.allow', 'fingerprint' => hash('sha256', (new EchoTool())->description())],
            'secret' => ['enabled' => true, 'class' => HiddenTool::class, 'gate' => 'echo.allow', 'fingerprint' => hash('sha256', (new HiddenTool())->description())],
        ]]);
        $this->recordReceipts();

        $responses = $this->send(
            $this->toolCall(1, 'echo', ['text' => 'hi']),
            $this->toolCall(2, 'echo', ['text' => 'hi', 'foo' => 'x']),
            $this->toolCall(3, 'secret', []),
            json_encode(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['arguments' => []]], JSON_THROW_ON_ERROR),
        );

        $this->assertCount(4, $responses);
        $this->assertCount(4, $this->receipts);

        $ids = array_map(fn (string $line): string => json_decode($line, true, 512, JSON_THROW_ON_ERROR)['id'], $this->receipts);
        $this->assertCount(4, array_unique($ids));
    }

    /** @param array<string, mixed> $arguments */
    private function toolCall(int $id, string $name, array $arguments): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], JSON_THROW_ON_ERROR);
    }

    private function recordReceipts(): void
    {
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->receipts[] = $line;
        });
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
