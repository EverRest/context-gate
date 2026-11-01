<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use ContextGate\Runbook\RunbookFingerprint;
use ContextGate\Tests\Fixtures\Runbook\MixedPlusRunbook;
use ContextGate\Tests\Fixtures\Runbook\MixedRunbook;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** spec 018 FR-020, US4: a step added after approval refuses the tool until gate:fingerprint refreshes the pin. */
class RunbookFingerprintRefusalTest extends TestCase
{
    use RunbookToolHarness;

    public function test_a_changed_step_list_is_refused_until_the_fingerprint_is_refreshed(): void
    {
        $approved = RunbookFingerprint::of(new MixedRunbook());
        $this->configureRunbookTool(MixedPlusRunbook::class, 'mixed', fingerprint: $approved);

        $refused = $this->rawCall('runbook.mixed');

        $this->assertArrayHasKey('error', $refused);
        $this->assertSame('tool.pin_mismatch', $this->lastReceipt()['reason']);

        $this->assertSame(0, Artisan::call('gate:fingerprint', ['tool' => 'runbook.mixed']));
        $refreshed = trim(Artisan::output());
        $this->assertSame(RunbookFingerprint::of(new MixedPlusRunbook()), $refreshed);

        $this->configureRunbookTool(MixedPlusRunbook::class, 'mixed', fingerprint: $refreshed);
        $this->assertFalse($this->rawCall('runbook.mixed')['result']['isError']);
    }
}
