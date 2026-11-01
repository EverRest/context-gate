<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Pipeline\Caps;
use ContextGate\Settings\Profiles;
use ContextGate\Settings\SecurityProfile;
use PHPUnit\Framework\TestCase;

/** spec 007 T006 (research R2): two profiles, the same keys, strict at or below balanced, balanced at the caps. */
class ProfilesTest extends TestCase
{
    public function test_there_are_exactly_two_profiles(): void
    {
        $this->assertSame(['strict', 'balanced'], array_map(static fn (SecurityProfile $p): string => $p->value, SecurityProfile::cases()));
    }

    public function test_both_profiles_hold_the_same_keys(): void
    {
        $this->assertSame(
            array_keys(Profiles::for(SecurityProfile::Balanced)),
            array_keys(Profiles::for(SecurityProfile::Strict)),
        );
        $this->assertSame([
            'firewall.limits.per_call_bytes',
            'firewall.limits.per_session_bytes',
            'firewall.limits.per_day_bytes',
            'firewall.limits.calls_per_minute',
            'sources.openapi.timeout_seconds',
            'sources.openapi.max_bytes',
        ], array_keys(Profiles::for(SecurityProfile::Strict)));
    }

    public function test_balanced_holds_the_code_caps(): void
    {
        $this->assertSame([
            'firewall.limits.per_call_bytes' => Caps::PER_CALL,
            'firewall.limits.per_session_bytes' => Caps::PER_SESSION,
            'firewall.limits.per_day_bytes' => Caps::PER_DAY,
            'firewall.limits.calls_per_minute' => Caps::PER_MINUTE,
            'sources.openapi.timeout_seconds' => Caps::OPENAPI_TIMEOUT,
            'sources.openapi.max_bytes' => Caps::OPENAPI_BYTES,
        ], Profiles::for(SecurityProfile::Balanced));
    }

    public function test_every_strict_value_is_at_most_its_balanced_value(): void
    {
        $balanced = Profiles::for(SecurityProfile::Balanced);

        foreach (Profiles::for(SecurityProfile::Strict) as $key => $value) {
            $this->assertLessThanOrEqual($balanced[$key], $value, $key);
        }
    }
}
