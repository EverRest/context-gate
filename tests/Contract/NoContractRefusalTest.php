<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Server\Fingerprint;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

/** F4: an enabled tool without a contract is refused by policy before its handler runs. */
class NoContractRefusalTest extends TestCase
{
    use GateCallHarness;

    public function test_an_enabled_tool_without_a_contract_is_refused_before_the_handler_runs(): void
    {
        $this->recordLines();
        Gate::define('echo.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['probe']]);
        config(['context-gate.classification' => []]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'probe' => [
                'enabled' => true,
                'class' => ProbeTool::class,
                'gate' => 'echo.allow',
                'fingerprint' => Fingerprint::of(new ProbeTool()),
            ],
        ]]);
        ProbeTool::$called = false;

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertTrue($result['isError']);
        $this->assertSame('Request refused.', $result['content'][0]['text']);
        $this->assertFalse(ProbeTool::$called, 'the handler must not run for a tool without a contract');
        $receipt = json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('policy.denied', $receipt['reason']);
    }
}
