<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\RoleFixture;
use ContextGate\Server\GateServer;
use ContextGate\Server\SourceTools;
use ContextGate\Tests\TestCase;
use Laravel\Mcp\Server\Transport\FakeTransporter;

class ToolsListSchemaTest extends TestCase
{
    private function raw(): string
    {
        $entries = SourceTools::entries();
        $tools = [];

        foreach ($entries as $name => $entry) {
            $tools[$name] = ['enabled' => true] + $entry;
        }

        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.enabled' => true, 'context-gate.tools' => $tools, ...RoleFixture::stdio('alice', array_keys($tools))]);

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

        return $transport->sent[0];
    }

    public function test_empty_properties_serialise_as_an_object_and_nothing_is_a_bare_array(): void
    {
        $raw = $this->raw();

        $this->assertStringContainsString('"properties":{}', $raw);
        $this->assertStringNotContainsString('"properties":[]', $raw);
    }

    public function test_every_tool_forbids_extra_arguments_and_describes_each_property(): void
    {
        $tools = json_decode($this->raw(), true, 512, JSON_THROW_ON_ERROR)['result']['tools'];

        $this->assertCount(5, $tools);

        foreach ($tools as $tool) {
            $this->assertNotSame('', $tool['description']);
            $this->assertFalse($tool['inputSchema']['additionalProperties'], $tool['name']);

            foreach ($tool['inputSchema']['properties'] as $name => $property) {
                $this->assertNotSame('', (string) ($property['description'] ?? ''), "{$tool['name']}.{$name} description");
                $this->assertNotEmpty($property['examples'] ?? [], "{$tool['name']}.{$name} example");
            }
        }
    }

    public function test_only_openapi_endpoints_is_open_world(): void
    {
        $tools = json_decode($this->raw(), true, 512, JSON_THROW_ON_ERROR)['result']['tools'];

        foreach ($tools as $tool) {
            $this->assertSame($tool['name'] === 'openapi.endpoints', $tool['annotations']['openWorldHint'], $tool['name']);
            $this->assertTrue($tool['annotations']['readOnlyHint']);
        }
    }
}
