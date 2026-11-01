<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Pipeline\Caps;
use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\CustomerContract;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;

/** spec 007 T025 (US2, R8): an invalid settings document refuses every call with config.invalid, before the handler. */
class BootValidationTest extends TestCase
{
    use GateCallHarness;

    public function test_an_invalid_document_refuses_the_call_with_config_invalid_and_the_receipt_says_so(): void
    {
        $this->recordLines();
        $this->configureTool(CustomerContract::class, ['customers' => ['customer' => 'public', 'email' => 'internal']]);
        config(['context-gate.firewall.limits.per_call_bytes' => Caps::PER_CALL + 1]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertTrue($result['isError']);
        $this->assertSame('Request refused.', $result['content'][0]['text']);
        $this->assertFalse(ProbeTool::$called, 'the handler must not run with an invalid document');
        $receipt = json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('config.invalid', $receipt['reason']);
    }

    public function test_a_valid_document_leaves_the_response_unchanged(): void
    {
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public']]);
        ProbeTool::$output = (string) json_encode([['status' => 'paid']]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertFalse($result['isError']);
        $this->assertTrue(ProbeTool::$called);
        $receipt = json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotSame('config.invalid', $receipt['reason'] ?? null);
    }
}
