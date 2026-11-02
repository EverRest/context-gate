<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Classification\Ceiling;
use ContextGate\Pipeline\Caps;
use ContextGate\Settings\ActiveSettings;
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

    public function test_the_limit_is_the_profile_value_for_null_config_under_strict(): void
    {
        config(['context-gate' => [], 'app.env' => 'production']);

        $this->assertSame(16384, app(ActiveSettings::class)->limit('firewall.limits.per_call_bytes'));
    }

    public function test_the_limit_is_the_cap_under_balanced_outside_production(): void
    {
        config(['context-gate' => ['profile' => 'balanced'], 'app.env' => 'local']);

        $this->assertSame(Caps::PER_CALL, app(ActiveSettings::class)->limit('firewall.limits.per_call_bytes'));
        $this->assertSame(Caps::PER_MINUTE, app(ActiveSettings::class)->limit('firewall.limits.calls_per_minute'));
    }

    /** spec 007 FR-005, edge cases: an unset, empty or production APP_ENV all resolve to strict. Pinned before the helper refactor. */
    public function test_unset_empty_and_production_app_env_all_resolve_to_strict_values(): void
    {
        foreach ([null, '', 'production'] as $env) {
            // balanced is refused in production (SettingsValidator), so the resolution is checked on the pure function.
            $values = EffectiveSettings::for(['profile' => 'balanced'], (string) $env);

            $this->assertSame(16384, $values['firewall.limits.per_call_bytes']);
            $this->assertSame(5242880, $values['firewall.limits.per_day_bytes']);
        }
    }

    /** spec 007 FR-017, spec 002: the configured ceiling class; the code default is internal; a class no call can use makes the settings invalid. */
    public function test_the_ceiling_in_use_is_the_configured_class_or_internal_by_default(): void
    {
        config(['context-gate' => []]);
        $this->assertSame(Ceiling::Internal, app(ActiveSettings::class)->ceiling());

        config(['context-gate.firewall.ceiling' => 'public']);
        $this->assertSame(Ceiling::Public, app(ActiveSettings::class)->ceiling());

        config(['context-gate.firewall.ceiling' => 'bogus']);
        $this->assertFalse(app(ActiveSettings::class)->valid());
    }

    /** spec 007 FR-017 amendment 2026-11-01: only public and internal are accepted; personal and secret make the settings invalid. */
    public function test_personal_and_secret_configured_ceilings_make_the_settings_invalid(): void
    {
        foreach (['personal', 'secret'] as $ceiling) {
            config(['context-gate.firewall.ceiling' => $ceiling]);
            $this->assertFalse(app(ActiveSettings::class)->valid(), $ceiling);
        }
    }

    /** spec 007 FR-017: drop is the default; redacted keeps examples; any other value makes the settings invalid. */
    public function test_the_openapi_examples_mode_is_drop_by_default_and_invalid_when_unknown(): void
    {
        config(['context-gate' => []]);
        $this->assertSame('drop', app(ActiveSettings::class)->examples());

        config(['context-gate.sources.openapi.examples' => 'redacted']);
        $this->assertSame('redacted', app(ActiveSettings::class)->examples());

        config(['context-gate.sources.openapi.examples' => 'keep']);
        $this->assertFalse(app(ActiveSettings::class)->valid());
    }

    /**
     * spec 009 FR-007, T009: an over-cap value makes the document invalid (SettingsValidator), so it never reaches a consumer.
     * for() is called on valid documents only and no longer replaces an explicit value with the profile value.
     */
    public function test_for_keeps_an_over_cap_value_because_such_a_document_is_invalid(): void
    {
        $values = EffectiveSettings::for(['firewall' => ['limits' => ['per_day_bytes' => Caps::PER_DAY + 1]]], 'local');

        $this->assertSame(Caps::PER_DAY + 1, $values['firewall.limits.per_day_bytes']);
    }
}
