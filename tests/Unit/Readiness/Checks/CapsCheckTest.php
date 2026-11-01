<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness\Checks;

use ContextGate\Classification\Catalog;
use ContextGate\Pipeline\Caps;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\Checks\CapsCheck;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Tests\TestCase;

/** T020, spec 002 FR-022: a configured limit above its code cap fails and names the key. */
class CapsCheckTest extends TestCase
{
    public function test_a_per_call_limit_above_the_cap_fails_and_names_the_key(): void
    {
        $ctx = new ReadinessContext(
            ['firewall' => ['limits' => ['per_call_bytes' => Caps::PER_CALL + 1]]],
            new Catalog([]),
            [],
        );

        $result = (new CapsCheck())->run($ctx);

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('context-gate.firewall.limits.per_call_bytes', $result->subject);
        $this->assertNotSame('', $result->fix);
    }

    public function test_limits_at_or_below_the_caps_pass(): void
    {
        $ctx = new ReadinessContext(
            ['firewall' => ['limits' => ['per_call_bytes' => Caps::PER_CALL, 'per_day_bytes' => null]]],
            new Catalog([]),
            [],
        );

        $this->assertSame(CheckStatus::Pass, (new CapsCheck())->run($ctx)->status);
    }
}
