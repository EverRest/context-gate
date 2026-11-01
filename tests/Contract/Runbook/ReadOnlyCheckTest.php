<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use ContextGate\Tests\Fixtures\Runbook\UnsafeRunbook;
use ContextGate\Tests\TestCase;

/** spec 018 US3 AC3, FR-006, SC-003: a step not declared read-only refuses every MCP call of its runbook. */
class ReadOnlyCheckTest extends TestCase
{
    use RunbookToolHarness;

    public function test_a_runbook_with_a_writable_step_is_refused_on_every_call(): void
    {
        $this->configureRunbookTool(UnsafeRunbook::class, 'unsafe');

        $result = $this->rawCall('runbook.unsafe')['result'];

        $this->assertTrue($result['isError']);
        $this->assertStringNotContainsString('would write', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame('config.invalid', $this->lastReceipt()['reason']);
    }
}
