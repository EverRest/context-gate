<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;

/** spec 007 T027 (characterization of spec 001 FR-004, FR-005): a tool a caller does not list answers like an unknown tool. */
class CallerToolsTest extends TestCase
{
    use GateCallHarness;

    public function test_an_unlisted_tool_answers_like_an_unknown_tool_and_records_policy_hidden(): void
    {
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public']]);
        config(['context-gate.callers.alice.tools' => ['probe']]);
        config(['context-gate.callers.bob.tools' => []]);

        app()->instance('context-gate.caller', 'alice');
        $this->send($this->toolCall('probe'));
        $this->assertNotSame('policy.hidden', $this->lastReceipt()['reason'], 'alice lists probe');

        app()->instance('context-gate.caller', 'bob');
        $hidden = $this->send($this->toolCall('probe'));
        $this->assertSame('policy.hidden', $this->lastReceipt()['reason']);

        $unknown = $this->send($this->toolCall('missing'));

        $this->assertSame($unknown, $hidden);
        $this->assertFalse(ProbeTool::$called, 'the handler must not run for bob');
    }

    private function toolCall(string $name): string
    {
        return (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => ['text' => 'x']],
        ], JSON_THROW_ON_ERROR);
    }
}
