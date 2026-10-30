<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\GateServer;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\Fixtures\HiddenTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\Transport\FakeTransporter;

class ReceiptHiddenTest extends TestCase
{
    public function test_hidden_call_writes_policy_hidden_receipt_and_response_has_no_reason(): void
    {
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['echo']]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'echo' => ['enabled' => true, 'class' => EchoTool::class, 'gate' => 'echo.allow', 'fingerprint' => hash('sha256', (new EchoTool())->description())],
            'secret' => ['enabled' => true, 'class' => HiddenTool::class, 'gate' => 'echo.allow', 'fingerprint' => hash('sha256', (new HiddenTool())->description())],
        ]]);

        $lines = [];
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $response = $this->send(json_encode([
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'tools/call',
            'params' => ['name' => 'secret', 'arguments' => []],
        ]))[0];

        $this->assertCount(1, $lines);
        $record = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('hidden', $record['decision']);
        $this->assertSame('policy.hidden', $record['reason']);
        $this->assertSame('secret', $record['tool']);
        $this->assertSame('alice', $record['caller']);

        $this->assertStringNotContainsString('policy.hidden', $response);
        $this->assertStringNotContainsString('hidden', $response);
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
