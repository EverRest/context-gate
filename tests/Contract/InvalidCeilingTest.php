<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\CustomerContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;

/** F5: an invalid configured ceiling refuses the call before the handler runs. */
class InvalidCeilingTest extends TestCase
{
    use GateCallHarness;

    public function test_an_invalid_ceiling_refuses_the_call_before_the_handler_runs(): void
    {
        $this->recordLines();
        $this->configureTool(CustomerContract::class, ['customers' => ['customer' => 'public', 'email' => 'internal']]);
        config(['context-gate.firewall.ceiling' => 'bogus']);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertTrue($result['isError']);
        $this->assertSame('Request refused.', $result['content'][0]['text']);
        $this->assertFalse(ProbeTool::$called, 'the handler must not run with an invalid ceiling');
        $receipt = json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('config.invalid', $receipt['reason']);
    }
}
