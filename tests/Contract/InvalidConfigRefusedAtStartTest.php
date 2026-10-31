<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\GateServer;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\TestCase;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use LogicException;

class InvalidConfigRefusedAtStartTest extends TestCase
{
    public function test_a_tool_entry_without_a_handler_class_refuses_to_start_and_names_the_entry(): void
    {
        config(['context-gate.tools' => ['broken_tool' => ['enabled' => true]]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('broken_tool');

        (new GateServer(new FakeTransporter()))->start();
    }

    public function test_two_tools_with_the_same_name_refuse_to_start(): void
    {
        $entry = ['enabled' => true, 'class' => EchoTool::class];
        config(['context-gate.tools' => ['one' => $entry, 'two' => $entry]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('echo');

        (new GateServer(new FakeTransporter()))->start();
    }
}
