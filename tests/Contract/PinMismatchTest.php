<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\RoleFixture;
use ContextGate\Server\GateServer;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\Transport\FakeTransporter;

class PinMismatchTest extends TestCase
{
    /** @var list<string> */
    private array $receipts = [];

    public function test_tool_with_stale_fingerprint_is_hidden_and_receipt_records_pin_mismatch(): void
    {
        Gate::define('echo.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(RoleFixture::stdio('alice', ['echo']));
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'echo' => [
                'enabled' => true,
                'class' => EchoTool::class,
                'gate' => 'echo.allow',
                'fingerprint' => hash('sha256', 'Approved description before the change.'),
            ],
        ]]);
        $this->recordReceipts();

        $response = json_decode($this->send(json_encode([
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'tools/call',
            'params' => ['name' => 'echo', 'arguments' => ['text' => 'hi']],
        ], JSON_THROW_ON_ERROR))[0], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['code' => -32602, 'message' => 'Tool not found.'], $response['error']);

        $this->assertCount(1, $this->receipts);
        $record = json_decode($this->receipts[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('hidden', $record['decision']);
        $this->assertSame('tool.pin_mismatch', $record['reason']);
        $this->assertSame('echo', $record['tool']);
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
