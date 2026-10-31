<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\GateServer;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\Transport\FakeTransporter;

class MissingToolNameTest extends TestCase
{
    /** @var list<string> */
    private array $receipts = [];

    /** @return array<string, array{array<string, mixed>}> */
    public static function badParams(): array
    {
        return [
            'missing' => [['arguments' => []]],
            'not a string' => [['name' => ['echo'], 'arguments' => []]],
            'empty' => [['name' => '', 'arguments' => []]],
        ];
    }

    /** @param array<string, mixed> $params */
    #[\PHPUnit\Framework\Attributes\DataProvider('badParams')]
    public function test_a_call_without_a_usable_name_is_input_invalid_and_names_the_parameter(array $params): void
    {
        app()->instance('context-gate.caller', 'alice');
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->receipts[] = $line;
        });

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
        $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params], JSON_THROW_ON_ERROR));

        $error = json_decode($transport->sent[0], true, 512, JSON_THROW_ON_ERROR)['error'];
        $this->assertSame(-32602, $error['code']);
        $this->assertStringContainsString('[name]', $error['message']);

        $this->assertCount(1, $this->receipts);
        $this->assertSame('input.invalid', json_decode($this->receipts[0], true, 512, JSON_THROW_ON_ERROR)['reason']);
    }
}
