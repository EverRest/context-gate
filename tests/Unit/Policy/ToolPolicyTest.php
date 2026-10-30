<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Policy;

use ContextGate\Policy\ToolPolicy;
use ContextGate\Server\Caller;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

class ToolPolicyTest extends TestCase
{
    public function test_allows_returns_result_of_configured_gate_ability(): void
    {
        Gate::define('echo.allow', fn (): bool => true);
        Gate::define('echo.deny', fn (): bool => false);
        $caller = new Caller('alice', ['echo']);
        $policy = new ToolPolicy();

        config(['context-gate.tools.echo.gate' => 'echo.allow']);
        $this->assertTrue($policy->allows('echo', $caller, ['text' => 'hi']));

        config(['context-gate.tools.echo.gate' => 'echo.deny']);
        $this->assertFalse($policy->allows('echo', $caller, ['text' => 'hi']));
    }

    public function test_missing_ability_denies(): void
    {
        config(['context-gate.tools.echo.gate' => 'echo.undefined']);

        $this->assertFalse((new ToolPolicy())->allows('echo', new Caller('alice', ['echo']), ['text' => 'hi']));
    }
}
