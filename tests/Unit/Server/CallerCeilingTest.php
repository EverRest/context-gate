<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Classification\Ceiling;
use ContextGate\Server\Caller;
use ContextGate\Tests\Fixtures\RoleFixture;
use ContextGate\Tests\TestCase;

/** spec 010 FR-004, US2, SC-002: a call's ceiling is the narrower of the transport's and the role's. */
class CallerCeilingTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> token or stdio ceiling, role ceiling, expected */
    public static function pairs(): iterable
    {
        $levels = ['public', 'internal', 'personal'];

        foreach ($levels as $transport) {
            foreach ($levels as $role) {
                $narrower = array_search($transport, $levels, true) <= array_search($role, $levels, true) ? $transport : $role;
                yield "transport {$transport}, role {$role}" => [$transport, $role, $narrower];
            }
        }
    }

    /** Over HTTP the transport ceiling is the token's, bound by the server. */
    #[\PHPUnit\Framework\Attributes\DataProvider('pairs')]
    public function test_over_http_the_ceiling_is_the_narrower_of_token_and_role(string $transport, string $role, string $expected): void
    {
        app()->instance('context-gate.caller', 'token:1');
        app()->instance('context-gate.role', 'support');
        app()->instance('context-gate.ceiling', $transport);
        config(['context-gate.roles.support' => ['tools' => [], 'ceiling' => $role]]);

        $this->assertSame(Ceiling::from($expected), Caller::ceiling());
    }

    public function test_over_stdio_the_firewall_ceiling_is_narrowed_by_the_role(): void
    {
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.firewall.ceiling' => 'internal']);
        config(RoleFixture::stdio('alice', []));
        config(['context-gate.roles.alice.ceiling' => 'public']);

        $this->assertSame(Ceiling::Public, Caller::ceiling());
    }

    public function test_over_stdio_a_personal_role_does_not_widen_the_internal_firewall_ceiling(): void
    {
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.firewall.ceiling' => 'internal']);
        config(RoleFixture::stdio('alice', []));

        $this->assertSame(Ceiling::Internal, Caller::ceiling());
    }

    public function test_a_caller_with_no_defined_role_has_no_ceiling(): void
    {
        app()->instance('context-gate.caller', 'bob');

        $this->assertNull(Caller::ceiling());
    }
}
