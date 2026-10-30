<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\GateServer;
use ContextGate\Tests\TestCase;
use Laravel\Mcp\Server\Transport\FakeTransporter;

class ToolsListEmptyTest extends TestCase
{
    public function test_tools_list_is_empty_with_default_configuration(): void
    {
        $responses = $this->send(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']));

        $this->assertSame([], json_decode($responses[0], true, 512, JSON_THROW_ON_ERROR)['result']['tools']);
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
