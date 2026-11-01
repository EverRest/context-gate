<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** spec 007 T035 (US5, FR-006, research R6): gate:check fails and names the key when the file config tries to set redaction or log_level. */
class RedactionLockTest extends TestCase
{
    public function test_gate_check_fails_and_names_redaction_when_it_is_set(): void
    {
        config(['context-gate.redaction' => false]);

        $exit = Artisan::call('gate:check');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('context-gate.redaction', Artisan::output());
    }

    public function test_gate_check_fails_and_names_log_level_when_it_is_off(): void
    {
        config(['context-gate.log_level' => 'off']);

        $exit = Artisan::call('gate:check');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('context-gate.log_level', Artisan::output());
    }
}
