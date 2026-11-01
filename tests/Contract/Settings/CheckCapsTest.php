<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** spec 007 T023 (US2 independent test, FR-001, FR-011): gate:check names every limit above its cap. */
class CheckCapsTest extends TestCase
{
    public function test_a_per_call_limit_above_its_cap_fails_and_names_the_key(): void
    {
        config(['context-gate.firewall.limits.per_call_bytes' => 70000]);

        $exit = Artisan::call('gate:check');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('context-gate.firewall.limits.per_call_bytes', Artisan::output());
    }

    public function test_a_per_call_limit_at_its_cap_is_not_reported(): void
    {
        config(['context-gate.firewall.limits.per_call_bytes' => 65536]);

        Artisan::call('gate:check');

        $this->assertStringNotContainsString('per_call_bytes', Artisan::output());
    }

    public function test_two_limits_above_their_caps_are_both_named_in_one_run(): void
    {
        config([
            'context-gate.firewall.limits.per_call_bytes' => 70000,
            'context-gate.firewall.limits.calls_per_minute' => 301,
        ]);

        Artisan::call('gate:check');
        $output = Artisan::output();

        $this->assertStringContainsString('per_call_bytes', $output);
        $this->assertStringContainsString('calls_per_minute', $output);
    }
}
