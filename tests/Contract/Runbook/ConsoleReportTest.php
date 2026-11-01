<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use ContextGate\Tests\Fixtures\Runbook\MixedRunbook;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** spec 018 US1 (FR-013): gate:runbook prints one row per step, in declared order, and exits 1 on a fail. */
class ConsoleReportTest extends TestCase
{
    public function test_it_prints_one_row_per_step_in_declared_order(): void
    {
        config(['context-gate.runbooks' => ['mixed' => MixedRunbook::class]]);

        $exit = Artisan::call('gate:runbook', ['name' => 'mixed']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('runbook: mixed (3 steps)', $output);
        $this->assertMatchesRegularExpression('/ok_step\s+ok\s+first is ok.*warn_step\s+warn\s+second warns.*fail_step\s+fail\s+third fails/s', $output);
        $this->assertStringContainsString('summary: 1 fail, 1 warn, 1 ok; 0 rows dropped', $output);
    }
}
