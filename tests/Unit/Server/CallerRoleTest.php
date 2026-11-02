<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\Caller;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\TestCase;

/** spec 010 US1, US4, research R3: the tools of an HTTP caller come from its token's role; a token with no role never reads stdio_roles. */
class CallerRoleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['context-gate.tools' => ['echo' => ['enabled' => true, 'class' => EchoTool::class]]]);
        config(['context-gate.roles' => [
            'alpha' => ['tools' => ['echo'], 'ceiling' => 'internal'],
        ]]);
    }

    public function test_an_http_caller_gets_the_tools_of_its_role(): void
    {
        app()->instance('context-gate.caller', 'token:1');
        app()->instance('context-gate.role', 'alpha');

        $this->assertSame(['echo'], Caller::current()->tools);
        $this->assertSame('alpha', Caller::current()->role);
    }

    public function test_an_http_caller_with_no_role_gets_no_tools(): void
    {
        app()->instance('context-gate.caller', 'token:1');
        app()->instance('context-gate.role', '');

        $this->assertSame([], Caller::current()->tools);
        $this->assertNull(Caller::current()->role);
    }

    public function test_an_http_caller_with_an_undefined_role_gets_no_tools(): void
    {
        app()->instance('context-gate.caller', 'token:1');
        app()->instance('context-gate.role', 'ghost');

        $this->assertSame([], Caller::current()->tools);
    }

    public function test_a_token_with_no_role_does_not_pick_up_a_stdio_mapping_of_its_id(): void
    {
        config(['context-gate.stdio_roles' => ['token:1' => 'alpha', 'unknown' => 'alpha']]);

        app()->instance('context-gate.caller', 'token:1');
        app()->instance('context-gate.role', '');

        $this->assertSame([], Caller::current()->tools);
        $this->assertNull(Caller::current()->role);
    }

    public function test_a_caller_without_a_role_binding_uses_the_stdio_mapping_of_its_name(): void
    {
        config(['context-gate.stdio_roles' => ['dev' => 'alpha']]);
        app()->instance('context-gate.caller', 'dev');

        $this->assertSame(['echo'], Caller::current()->tools);
    }
}
