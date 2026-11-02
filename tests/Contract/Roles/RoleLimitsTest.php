<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Roles;

use ContextGate\Settings\SettingsValidator;
use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\RoleFixture;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

/** spec 010 US5, FR-005: a role limit lowers the profile value for each caller of the role; counters stay per caller. */
class RoleLimitsTest extends TestCase
{
    use GateCallHarness;

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        $this->recordLines();
        Gate::define('echo.allow', fn (): bool => true);
        $this->configureTool('ContextGate\\Tests\\Fixtures\\ProbeContract', ['customers' => ['id' => 'public']]);
    }

    private function callAs(string $user): void
    {
        app()->instance('context-gate.caller', $user);
        $this->send((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'probe', 'arguments' => ['text' => 'x']]], JSON_THROW_ON_ERROR));
    }

    public function test_a_role_limit_refuses_the_call_after_that_many_calls(): void
    {
        config(RoleFixture::stdio('alice', ['probe']));
        config(['context-gate.roles.alice.limits.calls_per_minute' => 2]);

        $this->callAs('alice');
        $this->callAs('alice');
        $this->assertNotSame('rate.limited', $this->lastReceipt()['reason']);

        $this->callAs('alice');
        $this->assertSame('rate.limited', $this->lastReceipt()['reason']);
    }

    public function test_two_callers_of_one_role_keep_separate_counters(): void
    {
        config(array_merge(RoleFixture::stdio('alice', ['probe']), ['context-gate.stdio_roles.bob' => 'alice']));
        config(['context-gate.roles.alice.limits.calls_per_minute' => 1]);

        $this->callAs('alice');
        $this->callAs('bob');

        $this->assertNotSame('rate.limited', $this->lastReceipt()['reason']);
    }

    public function test_a_role_limit_above_the_profile_value_fails_validation_naming_it(): void
    {
        $document = ['roles' => ['alice' => ['tools' => [], 'ceiling' => 'internal', 'limits' => ['calls_per_minute' => 1000]]]];

        $this->assertSame(['roles.alice.limits.calls_per_minute'], array_column(SettingsValidator::violations($document, 'production'), 'key'));
    }
}
