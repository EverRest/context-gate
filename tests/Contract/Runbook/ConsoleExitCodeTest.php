<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use ContextGate\Tests\Fixtures\Runbook\MixedRunbook;
use ContextGate\Tests\Fixtures\Runbook\PassingRunbook;
use ContextGate\Tests\Fixtures\Runbook\SlowRunbook;
use ContextGate\Tests\Fixtures\Runbook\ThrowingRunbook;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** spec 018 FR-014: exit 0 for ok and warn, 1 for fail, timeout or error, 2 for an unregistered name. */
class ConsoleExitCodeTest extends TestCase
{
    public function test_all_ok_gives_zero(): void
    {
        config(['context-gate.runbooks' => ['passing' => PassingRunbook::class]]);

        $this->assertSame(0, Artisan::call('gate:runbook', ['name' => 'passing']));
    }

    public function test_an_unregistered_name_gives_two_and_lists_only_registered_names(): void
    {
        config(['context-gate.runbooks' => ['passing' => PassingRunbook::class]]);

        $this->assertSame(2, Artisan::call('gate:runbook', ['name' => 'disk-x']));
        $output = Artisan::output();

        $this->assertStringContainsString('runbook not found: disk-x', $output);
        $this->assertStringContainsString('registered: passing', $output);
        $this->assertStringNotContainsString('SECRET', $output);
    }

    public function test_a_timeout_gives_one(): void
    {
        config(['context-gate.runbooks' => ['slow' => SlowRunbook::class]]);

        $this->assertSame(1, Artisan::call('gate:runbook', ['name' => 'slow']));
        $this->assertStringContainsString('Step returned after its time limit; text discarded.', Artisan::output());
    }

    public function test_an_error_gives_one_and_prints_no_exception_text(): void
    {
        config(['context-gate.runbooks' => ['throwing' => ThrowingRunbook::class, 'mixed' => MixedRunbook::class]]);

        $this->assertSame(1, Artisan::call('gate:runbook', ['name' => 'throwing']));
        $output = Artisan::output();

        $this->assertStringContainsString('Step failed. See the operator note.', $output);
        $this->assertStringContainsString('still runs', $output);
        $this->assertStringNotContainsString('SECRET_MESSAGE_NEVER_PRINTED', $output);
    }
}
