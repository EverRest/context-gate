<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Pipeline\Caps;
use ContextGate\Settings\EffectiveSettings;
use PHPUnit\Framework\TestCase;

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

    public function test_a_per_tool_null_takes_the_effective_global_value(): void
    {
        $document = [
            'firewall' => ['limits' => ['per_call_bytes' => 1000]],
            'tools' => ['probe' => ['max_bytes' => null]],
        ];

        $this->assertSame(1000, EffectiveSettings::tool($document, 'development', 'probe')['max_bytes']);
    }

    public function test_a_per_tool_value_above_the_global_value_is_clamped_to_it(): void
    {
        $document = [
            'firewall' => ['limits' => ['per_call_bytes' => 1000]],
            'tools' => ['probe' => ['max_bytes' => 5000]],
        ];

        $this->assertSame(1000, EffectiveSettings::tool($document, 'development', 'probe')['max_bytes']);
    }
}
