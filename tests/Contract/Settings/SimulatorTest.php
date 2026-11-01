<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Server\Fingerprint;
use ContextGate\Server\Simulator;
use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fakes\CountingTool;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;

/** T054, spec 007 US7, FR-015 and SC-003: the simulator reports the decision of the real call and never calls the handler. */
class SimulatorTest extends TestCase
{
    use GateCallHarness;

    protected function setUp(): void
    {
        parent::setUp();

        CountingTool::$calls = 0;
        $this->recordLines();
        Gate::define('echo.allow', fn (): bool => true);
        Gate::define('echo.deny', fn (): bool => false);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers' => ['alice' => ['tools' => ['probe']], 'bob' => ['tools' => []]]]);
        config(['context-gate.classification' => ['customers' => ['id' => 'public', 'status' => 'public', 'note' => 'internal', 'email' => 'personal']]]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'probe' => [
                'enabled' => true,
                'class' => CountingTool::class,
                'contract' => ProbeContract::class,
                'gate' => 'echo.allow',
                'fingerprint' => Fingerprint::of(new CountingTool()),
            ],
        ]]);
    }

    public function test_hidden_tool_is_hidden_without_calling_the_handler_and_the_receipt_is_simulated(): void
    {
        $reason = Simulator::decide('bob', 'probe', ['text' => 'x']);

        $this->assertSame('hidden', $reason?->decision());
        $this->assertSame(0, CountingTool::$calls);
        $this->assertTrue($this->lastReceipt()['simulated']);
    }

    public function test_visible_tool_with_valid_arguments_is_allowed_without_calling_the_handler(): void
    {
        $reason = Simulator::decide('alice', 'probe', ['text' => 'x']);

        $this->assertNull($reason);
        $this->assertSame(0, CountingTool::$calls);
    }

    public function test_simulated_decision_equals_the_real_call_for_the_same_inputs(): void
    {
        $cases = [
            ['bob', 'probe', ['text' => 'x'], 'echo.allow'],
            ['alice', 'probe', ['text' => 'x'], 'echo.allow'],
            ['alice', 'probe', [], 'echo.allow'],
            ['alice', 'probe', ['text' => 'x'], 'echo.deny'],
        ];

        foreach ($cases as [$caller, $tool, $arguments, $gate]) {
            $this->configureGate($gate);
            app()->instance('context-gate.caller', $caller);

            $simulated = Simulator::decide($caller, $tool, $arguments)?->decision() ?? 'allow';

            $this->lines = [];
            $this->send((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]], JSON_THROW_ON_ERROR));
            $real = $this->lastReceipt()['decision'];

            $this->assertSame($real, $simulated, "{$caller} {$gate} ".json_encode($arguments));
        }
    }

    public function test_simulations_do_not_consume_the_per_minute_budget(): void
    {
        for ($i = 0; $i < 61; $i++) {
            Simulator::decide('alice', 'probe', ['text' => 'x']);
        }

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertFalse($result['isError']);
        $this->assertSame(1, CountingTool::$calls);
    }

    public function test_command_prints_the_decision_and_the_reason_without_values(): void
    {
        Artisan::call('gate:simulate', ['tool' => 'probe', 'caller' => 'bob', '--args' => '{"text":"secret-value"}']);

        $output = Artisan::output();

        $this->assertStringContainsString('Decision: hidden', $output);
        $this->assertStringContainsString('Reason: policy.hidden', $output);
        $this->assertStringNotContainsString('secret-value', $output);
        $this->assertSame(0, CountingTool::$calls);
    }

    public function test_command_refuses_an_unknown_caller(): void
    {
        $status = Artisan::call('gate:simulate', ['tool' => 'probe', 'caller' => 'nobody', '--args' => '{}']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('not a configured caller', Artisan::output());
    }

    public function test_command_refuses_in_production_like_gate_preview(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');

        $status = Artisan::call('gate:simulate', ['tool' => 'probe', 'caller' => 'bob', '--args' => '{}']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('gate:simulate does not run in production', Artisan::output());
        $this->assertSame(0, CountingTool::$calls);
    }

    private function configureGate(string $gate): void
    {
        config(['context-gate.tools.probe.gate' => $gate]);
    }
}
