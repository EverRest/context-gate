<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Pipeline\Caps;
use ContextGate\Settings\EffectiveSettings;
use ContextGate\Tests\TestCase;

/** spec 007 T015 (US2, FR-010): the value in use is the explicit one when valid, else the profile value. */
class EffectiveSettingsTest extends TestCase
{
    public function test_an_explicit_valid_value_wins_over_the_profile(): void
    {
        $values = EffectiveSettings::for(['firewall' => ['limits' => ['per_call_bytes' => 1000]]], 'development');

        $this->assertSame(1000, $values['firewall.limits.per_call_bytes']);
    }

    public function test_null_takes_the_profile_value(): void
    {
        $values = EffectiveSettings::for([], 'development');

        $this->assertSame(16384, $values['firewall.limits.per_call_bytes']);
        $this->assertSame(1048576, $values['sources.openapi.max_bytes']);
    }

    public function test_production_uses_strict_values_even_when_balanced_is_configured(): void
    {
        $values = EffectiveSettings::for(['profile' => 'balanced'], 'production');

        $this->assertSame(16384, $values['firewall.limits.per_call_bytes']);
    }

    public function test_balanced_outside_production_uses_the_caps(): void
    {
        $values = EffectiveSettings::for(['profile' => 'balanced'], 'development');

        $this->assertSame(Caps::PER_CALL, $values['firewall.limits.per_call_bytes']);
        $this->assertSame(Caps::OPENAPI_TIMEOUT, $values['sources.openapi.timeout_seconds']);
    }

    public function test_limit_returns_the_profile_value_for_null_config_under_strict(): void
    {
        config(['context-gate' => [], 'app.env' => 'production']);

        $this->assertSame(16384, EffectiveSettings::limit('firewall.limits.per_call_bytes'));
    }

    public function test_limit_returns_the_cap_under_balanced_outside_production(): void
    {
        config(['context-gate' => ['profile' => 'balanced'], 'app.env' => 'local']);

        $this->assertSame(Caps::PER_CALL, EffectiveSettings::limit('firewall.limits.per_call_bytes'));
        $this->assertSame(Caps::PER_MINUTE, EffectiveSettings::limit('firewall.limits.calls_per_minute'));
    }

    public function test_limit_clamps_an_over_cap_value_to_the_profile_value(): void
    {
        config(['context-gate' => ['firewall' => ['limits' => ['per_day_bytes' => Caps::PER_DAY + 1]]], 'app.env' => 'local']);

        $this->assertSame(EffectiveSettings::for([], 'local')['firewall.limits.per_day_bytes'], EffectiveSettings::limit('firewall.limits.per_day_bytes'));
        $this->assertLessThanOrEqual(Caps::PER_DAY, EffectiveSettings::limit('firewall.limits.per_day_bytes'));
    }
}
