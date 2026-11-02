<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\Caller;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\TestCase;

class CallerTest extends TestCase
{
    public function test_returns_bound_id_and_its_allowed_tools(): void
    {
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.tools' => ['echo' => ['enabled' => true, 'class' => EchoTool::class]]]);
        config(['context-gate.callers.alice.tools' => ['echo']]);

        $caller = Caller::current();

        $this->assertSame('alice', $caller->id);
        $this->assertSame(['echo'], $caller->tools);
    }

    public function test_a_dot_in_the_caller_id_is_not_treated_as_config_nesting(): void
    {
        app()->instance('context-gate.caller', 'first.last');
        config(['context-gate.tools' => ['echo' => ['enabled' => true, 'class' => EchoTool::class]]]);
        config(['context-gate.callers' => ['first.last' => ['tools' => ['echo']]]]);

        $this->assertSame(['echo'], Caller::current()->tools);
    }

    public function test_unknown_caller_has_no_tools(): void
    {
        app()->instance('context-gate.caller', 'bob');

        $this->assertSame([], Caller::current()->tools);
    }
}
