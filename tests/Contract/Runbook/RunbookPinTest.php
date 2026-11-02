<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use ContextGate\Tests\Fixtures\RoleFixture;
use ContextGate\Runbook\RunbookRowsContract;
use ContextGate\Source\RunbookTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

/** spec 018 FR-020: an unregistered runbook has no approved fingerprint, so an empty pin never approves it. */
class RunbookPinTest extends TestCase
{
    use RunbookToolHarness;

    public function test_an_unregistered_runbook_with_an_empty_pin_is_refused_and_not_listed(): void
    {
        Gate::define('runbook.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.runbooks' => []]);
        config(RoleFixture::stdio('alice', ['runbook.gone']));
        config(['context-gate.tools' => ['runbook.gone' => [
            'enabled' => true,
            'class' => RunbookTool::class,
            'contract' => RunbookRowsContract::class,
            'gate' => 'runbook.allow',
            'fingerprint' => '',
        ]]]);
        $this->recordLines();

        $this->assertNotContains('runbook.gone', $this->listedTools());
        $this->assertArrayHasKey('error', $this->rawCall('runbook.gone'));
        $this->assertSame('tool.pin_mismatch', $this->lastReceipt()['reason']);
    }
}
