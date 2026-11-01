<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use ContextGate\Tests\Fixtures\Runbook\MixedRunbook;
use ContextGate\Tests\TestCase;

/** spec 018 FR-011, spec 002 FR-007: the runbook report is internal; a caller below internal does not receive it. */
class RunbookCeilingTest extends TestCase
{
    use RunbookToolHarness;

    public function test_a_caller_with_internal_ceiling_receives_the_report(): void
    {
        $this->configureRunbookTool(MixedRunbook::class);
        config(['context-gate.firewall.ceiling' => 'internal']);

        $this->assertFalse($this->rawCall('runbook.mixed')['result']['isError']);
    }

    public function test_a_caller_with_public_ceiling_does_not_receive_the_report(): void
    {
        $this->configureRunbookTool(MixedRunbook::class);
        config(['context-gate.firewall.ceiling' => 'public']);

        $response = $this->rawCall('runbook.mixed');

        $this->assertStringNotContainsString('first is ok', json_encode($response, JSON_THROW_ON_ERROR));
        $this->assertSame('policy.denied', $this->lastReceipt()['reason']);
    }
}
