<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use ContextGate\Tests\Fixtures\Runbook\UnsafeRunbook;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** spec 018 FR-006: gate:runbook refuses a runbook that fails the validator (exit 2) and runs none of its steps. */
class ConsoleValidationTest extends TestCase
{
    public function test_an_invalid_runbook_is_refused_and_no_step_runs(): void
    {
        UnsafeRunbook::$runs = 0;
        config(['context-gate.runbooks' => ['unsafe' => UnsafeRunbook::class]]);

        $this->assertSame(2, Artisan::call('gate:runbook', ['name' => 'unsafe']));
        $this->assertStringContainsString('unsafe: step [writes] is not declared read-only', Artisan::output());
        $this->assertSame(0, UnsafeRunbook::$runs);
    }
}
