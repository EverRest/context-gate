<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** spec 007 T021 (US1 independent test): gate:check names the profile key when production runs a weaker profile. */
class CheckCommandProfileTest extends TestCase
{
    public function test_gate_check_fails_and_names_the_profile_in_production(): void
    {
        config(['app.env' => 'production', 'context-gate.profile' => 'balanced']);

        $exit = Artisan::call('gate:check');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('context-gate.profile', Artisan::output());
    }

    public function test_gate_check_does_not_name_the_profile_in_development_without_one(): void
    {
        config(['app.env' => 'development', 'context-gate.profile' => null]);

        Artisan::call('gate:check');

        $this->assertStringNotContainsString('context-gate.profile', Artisan::output());
    }
}
