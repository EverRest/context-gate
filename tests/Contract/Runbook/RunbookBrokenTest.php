<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use ContextGate\Tests\Fixtures\Registry;
use ContextGate\Runbook\RunbookFingerprint;
use ContextGate\Server\Caller;
use ContextGate\Source\RunbookTool;
use ContextGate\Tests\Fixtures\Runbook\BrokenStepsRunbook;
use ContextGate\Tests\Fixtures\Runbook\MixedRunbook;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** spec 018 FR-020, FR-021: a runbook whose steps cannot be built is refused alone and its message never prints. */
class RunbookBrokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
    }

    /** @return array<string, array<string, mixed>> */
    private function tools(): array
    {
        return [
            'runbook.mixed' => ['enabled' => true, 'class' => RunbookTool::class, 'fingerprint' => RunbookFingerprint::of(new MixedRunbook())],
            'runbook.broken' => ['enabled' => true, 'class' => RunbookTool::class, 'fingerprint' => 'FAKE_FIXTURE_ONLY'],
        ];
    }

    public function test_a_runbook_whose_steps_throw_does_not_stop_the_other_tools(): void
    {
        config(['context-gate.runbooks' => ['mixed' => MixedRunbook::class, 'broken' => BrokenStepsRunbook::class]]);

        $registry = Registry::of($this->tools());
        $caller = new Caller('alice', ['runbook.mixed', 'runbook.broken']);

        $this->assertTrue($registry->isVisible('runbook.mixed', $caller));
        $this->assertFalse($registry->isVisible('runbook.broken', $caller));
        $this->assertTrue($registry->isPinMismatch('runbook.broken'));
    }

    public function test_gate_check_names_the_broken_runbook_and_never_prints_its_message(): void
    {
        config(['context-gate.runbooks' => ['mixed' => MixedRunbook::class, 'broken' => BrokenStepsRunbook::class]]);

        Artisan::call('gate:check');
        $output = Artisan::output();

        $this->assertStringContainsString('FAIL runbooks.broken', $output);
        $this->assertStringNotContainsString('FAKE_FIXTURE_ONLY_SECRET', $output);
    }
}
