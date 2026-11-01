<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use ContextGate\Tests\Fixtures\Runbook\MixedRunbook;
use ContextGate\Tests\TestCase;

/** spec 018 FR-019, constitution III: runbook.<name> is listed for a caller only when enabled. */
class RunbookToolListTest extends TestCase
{
    use RunbookToolHarness;

    public function test_the_runbook_tool_is_listed_when_enabled_and_absent_when_disabled(): void
    {
        $this->configureRunbookTool(MixedRunbook::class);
        $this->assertContains('runbook.mixed', $this->listedTools());

        $this->configureRunbookTool(MixedRunbook::class, enabled: false);
        $this->assertNotContains('runbook.mixed', $this->listedTools());
    }
}
