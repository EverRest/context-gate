<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\RoleFixture;
use ContextGate\Server\GateServer;
use ContextGate\Server\Fingerprint;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\Transport\FakeTransporter;

class PolicyDeniedTest extends TestCase
{
    /** @var list<string> */
    private array $receipts = [];

    public function test_refused_call_returns_generic_refusal_and_policy_denied_receipt(): void
    {
        Gate::define('echo.deny', fn (): bool => false);
        app()->instance('context-gate.caller', 'alice');
        config(RoleFixture::stdio('alice', ['echo']));
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'echo' => ['enabled' => true, 'class' => EchoTool::class, 'gate' => 'echo.deny', 'fingerprint' => Fingerprint::of(new EchoTool())],
        ]]);
        $this->recordReceipts();

        $response = json_decode($this->send(json_encode([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => ['name' => 'echo', 'arguments' => ['text' => 'hi']],
        ], JSON_THROW_ON_ERROR))[0], true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($response['result']['isError']);
        $this->assertSame('Request refused.', $response['result']['content'][0]['text']);

        $this->assertCount(1, $this->receipts);
        $record = json_decode($this->receipts[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('denied', $record['decision']);
        $this->assertSame('policy.denied', $record['reason']);
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
