<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\GateServer;
use ContextGate\Tests\Fixtures\CustomerContract;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use RuntimeException;

class HandlerFailureTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    public function test_throwing_handler_returns_reference_only_and_writes_handler_failed_receipt(): void
    {
        Gate::define('echo.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['boom']]);
        config(['context-gate.classification' => ['customers' => ['customer' => 'public', 'email' => 'internal']]]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'boom' => ['enabled' => true, 'class' => ExplodingTool::class, 'contract' => CustomerContract::class, 'gate' => 'echo.allow', 'fingerprint' => hash('sha256', (new ExplodingTool())->description())],
        ]]);
        $this->recordLines();

        $raw = $this->send(json_encode([
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'tools/call',
            'params' => ['name' => 'boom', 'arguments' => ['text' => 'hi']],
        ], JSON_THROW_ON_ERROR))[0];

        $this->assertStringNotContainsString('SENTINEL_EXCEPTION_DETAIL', $raw);

        $response = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($response['result']['isError']);
        $this->assertMatchesRegularExpression(
            '/^Request failed\. Reference: (r_[0-9a-f]{8})$/',
            $response['result']['content'][0]['text'],
        );
        preg_match('/r_[0-9a-f]{8}/', $response['result']['content'][0]['text'], $matches);
        if (! isset($matches[0])) {
            $this->fail('No reference id found in the response text.');
        }

        $receipts = array_values(array_filter(
            array_map(fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $this->lines),
            fn (array $record): bool => array_key_exists('decision', $record),
        ));
        $this->assertCount(1, $receipts);
        $this->assertSame($matches[0], $receipts[0]['id']);
        $this->assertSame('failed', $receipts[0]['decision']);
        $this->assertSame('handler.failed', $receipts[0]['reason']);
        $this->assertStringNotContainsString('SENTINEL_EXCEPTION_DETAIL', json_encode($receipts[0], JSON_THROW_ON_ERROR));
    }

    private function recordLines(): void
    {
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info', 'error')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
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

final class ExplodingTool extends EchoTool
{
    public function name(): string
    {
        return 'boom';
    }

    public function handle(array $arguments): Response
    {
        throw new RuntimeException('SENTINEL_EXCEPTION_DETAIL');
    }
}
