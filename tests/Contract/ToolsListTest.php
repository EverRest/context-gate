<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\GateServer;
use ContextGate\Server\Fingerprint;
use ContextGate\Server\Tool;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\Fixtures\HiddenTool;
use ContextGate\Tests\TestCase;
use Laravel\Mcp\Server\Transport\FakeTransporter;

class ToolsListTest extends TestCase
{
    public function test_list_shows_only_the_tools_the_caller_may_see_with_name_description_and_schema(): void
    {
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['echo']]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'echo' => $this->tool(EchoTool::class),
            'secret' => $this->tool(HiddenTool::class),
        ]]);

        $tools = $this->list()['result']['tools'];

        $this->assertCount(1, $tools);
        $this->assertSame('echo', $tools[0]['name']);
        $this->assertSame((new EchoTool())->description(), $tools[0]['description']);
        $this->assertSame('string', $tools[0]['inputSchema']['properties']['text']['type']);
        $this->assertSame(
            ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            $tools[0]['annotations'],
        );
    }

    public function test_list_omits_a_tool_whose_description_no_longer_matches_its_fingerprint(): void
    {
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['echo']]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'echo' => ['fingerprint' => str_repeat('0', 64)] + $this->tool(EchoTool::class),
        ]]);

        $this->assertSame([], $this->list()['result']['tools']);
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
    private function list(): array
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
