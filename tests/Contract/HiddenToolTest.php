<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\GateServer;
use ContextGate\Server\Tool;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\Fixtures\HiddenTool;
use ContextGate\Tests\TestCase;
use Laravel\Mcp\Server\Transport\FakeTransporter;

class HiddenToolTest extends TestCase
{
    public function test_hidden_and_non_existent_tool_answer_with_identical_responses(): void
    {
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['echo']]);

        // Same name in both calls: the only difference is whether the tool is configured at all.
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'echo' => $this->tool(EchoTool::class),
            'secret' => $this->tool(HiddenTool::class),
        ]]);
        $hidden = $this->send($this->toolCall(7, 'secret'));

        config(['context-gate.tools' => ['echo' => $this->tool(EchoTool::class)]]);
        $nonExistent = $this->send($this->toolCall(7, 'secret'));

        $this->assertSame($hidden, $nonExistent);
        $this->assertSame(
            ['code' => -32602, 'message' => 'Tool [secret] not found.'],
            json_decode($hidden[0], true, 512, JSON_THROW_ON_ERROR)['error'],
        );
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
            'fingerprint' => hash('sha256', (new $class())->description()),
        ];
    }

    private function toolCall(int $id, string $name): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => []],
        ], JSON_THROW_ON_ERROR);
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
