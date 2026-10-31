<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\GateServer;
use ContextGate\Tests\TestCase;

/** Canary protection is off when the gate is enabled and no canary value is configured. */
class CanaryWarningTest extends TestCase
{
    public function test_an_enabled_gate_without_canary_values_names_the_key_and_says_protection_is_off(): void
    {
        config(['context-gate.enabled' => true, 'context-gate.canaries.values' => []]);

        $warning = GateServer::canaryWarning();

        $this->assertNotNull($warning);
        $this->assertStringContainsString('context-gate.canaries.values', $warning);
        $this->assertStringContainsString('canary protection is off', $warning);
    }

    public function test_a_configured_canary_value_gives_no_warning(): void
    {
        config(['context-gate.enabled' => true, 'context-gate.canaries.values' => ['FAKE_FIXTURE_ONLY']]);

        $this->assertNull(GateServer::canaryWarning());
    }

    public function test_a_disabled_gate_gives_no_warning(): void
    {
        config(['context-gate.enabled' => false, 'context-gate.canaries.values' => []]);

        $this->assertNull(GateServer::canaryWarning());
    }
}
