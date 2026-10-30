<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\Caller;
use ContextGate\Tests\TestCase;

class CallerTest extends TestCase
{
    public function test_returns_bound_id_and_its_allowed_tools(): void
    {
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['echo', 'ping']]);

        $caller = Caller::current();

        $this->assertSame('alice', $caller->id);
        $this->assertSame(['echo', 'ping'], $caller->tools);
    }

    public function test_unknown_caller_has_no_tools(): void
    {
        app()->instance('context-gate.caller', 'bob');

        $this->assertSame([], Caller::current()->tools);
    }
}
