<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use ContextGate\Server\ReasonCode;
use ContextGate\Source\RunbookTool;
use ContextGate\Source\SourceFailure;
use ContextGate\Tests\Fixtures\Runbook\MixedRunbook;
use ContextGate\Tests\TestCase;

/** spec 018 FR-011, spec 002 FR-007: the ceiling is checked before the runbook is looked up, so a missing name leaks nothing. */
class RunbookCeilingOrderTest extends TestCase
{
    use RunbookToolHarness;

    public function test_a_valid_and_a_missing_runbook_get_the_same_refusal_under_a_public_ceiling(): void
    {
        $this->configureRunbookTool(MixedRunbook::class);
        config(['context-gate.firewall.ceiling' => 'public']);

        $this->assertSame(ReasonCode::PolicyDenied, $this->refusalOf(new RunbookTool('runbook.mixed')));
        $this->assertSame(ReasonCode::PolicyDenied, $this->refusalOf(new RunbookTool('runbook.gone')));
    }

    private function refusalOf(RunbookTool $tool): ReasonCode
    {
        try {
            $tool->handle([]);
        } catch (SourceFailure $e) {
            return $e->reason;
        }

        $this->fail('the tool returned rows to a public caller');
    }
}
