<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;

/** spec 007 FR-016, research R9 (D2): a caller that lists a tool absent from tools refuses every call with config.invalid. */
class UnknownCallerToolTest extends TestCase
{
    use GateCallHarness;

    public function test_every_caller_gets_config_invalid_while_one_caller_lists_an_unknown_tool(): void
    {
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public']]);
        config(['context-gate.callers.alice.tools' => ['probe', 'missing']]);
        config(['context-gate.callers.bob.tools' => ['probe']]);

        foreach (['alice', 'bob'] as $caller) {
            app()->instance('context-gate.caller', $caller);

            $result = $this->callTool('probe', ['text' => 'x']);

            $this->assertTrue($result['isError'], $caller);
            $receipt = json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('config.invalid', $receipt['reason'], $caller);
        }

        $this->assertFalse(ProbeTool::$called, 'the handler must not run for any caller');
    }
}
