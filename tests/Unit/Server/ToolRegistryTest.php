<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\Caller;
use ContextGate\Server\Tool;
use ContextGate\Server\ToolRegistry;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\Fixtures\HiddenTool;
use ContextGate\Tests\TestCase;
use LogicException;

class ToolRegistryTest extends TestCase
{
    public function test_tool_is_visible_only_when_enabled_and_allowed_for_caller(): void
    {
        $registry = new ToolRegistry([
            'echo' => $this->tool(EchoTool::class),
            'secret' => $this->tool(HiddenTool::class),
        ]);
        $alice = new Caller('alice', ['echo']);

        $this->assertTrue($registry->isVisible('echo', $alice));
        $this->assertFalse($registry->isVisible('secret', $alice));
        $this->assertFalse($registry->isVisible('missing', $alice));
    }

    public function test_disabled_tool_is_hidden_even_when_allowed(): void
    {
        $registry = new ToolRegistry(['echo' => ['enabled' => false] + $this->tool(EchoTool::class)]);

        $this->assertFalse($registry->isVisible('echo', new Caller('alice', ['echo'])));
    }

    public function test_duplicate_tool_name_throws_on_boot(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('echo');

        new ToolRegistry([
            'echo' => $this->tool(EchoTool::class),
            'echo-copy' => $this->tool(EchoTool::class),
        ]);
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
}
