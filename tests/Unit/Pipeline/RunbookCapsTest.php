<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use ContextGate\Pipeline\Caps;
use PHPUnit\Framework\TestCase;

/** spec 018 T001 (FR-016, research R4): the runbook caps are code, not configuration. */
class RunbookCapsTest extends TestCase
{
    public function test_the_runbook_caps_match_the_research_values(): void
    {
        $this->assertSame(20, Caps::RUNBOOK_STEPS);
        $this->assertSame(5.0, Caps::RUNBOOK_STEP_TIMEOUT);
        $this->assertSame(2048, Caps::RUNBOOK_STEP_BYTES);
        $this->assertSame(8192, Caps::RUNBOOK_REPORT_BYTES);
        $this->assertSame(30.0, Caps::RUNBOOK_RUN_SECONDS);
    }
}
