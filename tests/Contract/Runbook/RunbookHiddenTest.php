<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use ContextGate\Tests\Fixtures\Runbook\MixedRunbook;
use ContextGate\Tests\TestCase;

/** spec 018 FR-019, spec 007 US3: a disabled runbook tool answers exactly like an unknown tool; the receipt says policy.hidden. */
class RunbookHiddenTest extends TestCase
{
    use RunbookToolHarness;

    public function test_a_disabled_runbook_answers_like_an_unknown_tool(): void
    {
        $this->configureRunbookTool(MixedRunbook::class, enabled: false);

        $disabled = $this->rawCall('runbook.mixed');
        $unknown = $this->rawCall('runbook.nothere');

        $this->assertArrayHasKey('error', $disabled);
        $this->assertSame($unknown['error'], $disabled['error']);
        $this->assertSame('policy.hidden', $this->lastReceipt()['reason']);
    }
}
