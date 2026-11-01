<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\Caller;
use ContextGate\Server\Fingerprint;
use ContextGate\Server\Tool;
use ContextGate\Server\ToolRegistry;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\Fixtures\HiddenTool;
use ContextGate\Tests\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

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

    #[DataProvider('validNames')]
    public function test_valid_tool_names_are_accepted(string $name): void
    {
        $this->assertNotNull((new ToolRegistry(['t' => $this->named($name)]))->find($name));
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_tool_names_throw_on_boot(string $name): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('name');

        new ToolRegistry(['t' => $this->named($name)]);
    }

    /** @return array<string, array{string}> */
    public static function validNames(): array
    {
        return ['dotted' => ['routes.list'], 'dotted2' => ['orders.status'], 'dash' => ['a-b_c'], 'max' => [str_repeat('a', 128)]];
    }

    /** @return array<string, array{string}> */
    public static function invalidNames(): array
    {
        return ['space' => ['a b'], 'slash' => ['a/b'], 'long' => [str_repeat('a', 129)], 'empty' => ['']];
    }

    /** @return array<string, mixed> */
    private function named(string $name): array
    {
        $tool = new class ($name) extends EchoTool {
            public function __construct(private readonly string $toolName)
            {
            }

            public function name(): string
            {
                return $this->toolName;
            }
        };
        app()->instance($tool::class, $tool);

        return ['enabled' => true, 'class' => $tool::class, 'fingerprint' => Fingerprint::of($tool)];
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
}
