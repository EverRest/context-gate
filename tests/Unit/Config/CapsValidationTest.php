<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Config;

use ContextGate\Config\CapsValidator;
use ContextGate\Pipeline\Caps;
use PHPUnit\Framework\TestCase;

class CapsValidationTest extends TestCase
{
    public function test_values_at_the_caps_pass(): void
    {
        $this->assertSame([], CapsValidator::violations([
            'firewall.limits.per_call_bytes' => Caps::PER_CALL,
            'firewall.limits.per_session_bytes' => Caps::PER_SESSION,
            'firewall.limits.per_day_bytes' => Caps::PER_DAY,
            'firewall.limits.calls_per_minute' => Caps::PER_MINUTE,
        ]));
    }

    public function test_a_value_above_its_cap_names_the_key(): void
    {
        $this->assertSame(
            ['firewall.limits.per_call_bytes'],
            CapsValidator::violations(['firewall.limits.per_call_bytes' => Caps::PER_CALL + 1]),
        );
    }

    public function test_each_limit_is_checked_against_its_own_cap(): void
    {
        $this->assertSame(
            ['firewall.limits.per_session_bytes', 'firewall.limits.calls_per_minute'],
            CapsValidator::violations([
                'firewall.limits.per_session_bytes' => Caps::PER_SESSION + 1,
                'firewall.limits.per_day_bytes' => Caps::PER_DAY,
                'firewall.limits.calls_per_minute' => Caps::PER_MINUTE + 1,
            ]),
        );
    }
}
