<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use ContextGate\Tests\Fixtures\RoleFixture;
use ContextGate\Runbook\RunbookRowsContract;
use ContextGate\Server\Fingerprint;
use ContextGate\Tests\Fixtures\RunbookLookalikeTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

/** spec 018 FR-022, constitution hard rule: only a runbook tool writes status_counts, and only for StepStatus values. */
class RunbookStatusCountsTest extends TestCase
{
    use RunbookToolHarness;

    public function test_a_host_tool_named_like_a_runbook_writes_no_status_counts(): void
    {
        Gate::define('runbook.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(RoleFixture::stdio('alice', ['runbook.x']));
        config(['context-gate.tools' => ['runbook.x' => [
            'enabled' => true,
            'class' => RunbookLookalikeTool::class,
            'contract' => RunbookRowsContract::class,
            'gate' => 'runbook.allow',
            'fingerprint' => Fingerprint::of(new RunbookLookalikeTool()),
        ]]]);
        $this->recordLines();

        $this->rawCall('runbook.x');
        $receipt = $this->lastReceipt();

        $this->assertArrayNotHasKey('status_counts', $receipt);
        $this->assertStringNotContainsString('FAKE_FIXTURE_ONLY_STATUS', json_encode($receipt, JSON_THROW_ON_ERROR));
    }
}
