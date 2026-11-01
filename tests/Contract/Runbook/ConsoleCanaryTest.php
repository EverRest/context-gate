<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use ContextGate\Tests\Fixtures\Runbook\CanaryRunbook;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** spec 018 FR-010, FR-013: a canary value in a step text is withheld from the console output. */
class ConsoleCanaryTest extends TestCase
{
    public function test_a_canary_in_a_step_text_is_withheld_and_exits_one(): void
    {
        config(['context-gate.runbooks' => ['canary' => CanaryRunbook::class]]);
        config(['context-gate.canaries.values' => ['FAKE_FIXTURE_ONLY_CANARY']]);

        $this->assertSame(1, Artisan::call('gate:runbook', ['name' => 'canary']));
        $output = Artisan::output();

        $this->assertStringContainsString('Output withheld: a canary value was found.', $output);
        $this->assertStringNotContainsString('FAKE_FIXTURE_ONLY_CANARY', $output);
    }
}
