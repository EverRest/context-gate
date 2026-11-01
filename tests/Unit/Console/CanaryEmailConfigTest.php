<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** The email canary must reach canaries.values too, or CanaryCheck never looks for it; the output says so. */
class CanaryEmailConfigTest extends TestCase
{
    public function test_output_gives_the_email_canary_for_configuration_as_well(): void
    {
        Artisan::call('gate:canary');
        $output = Artisan::output();

        preg_match('/documentation\s+(canary-[0-9a-f]{8}@example\.invalid)/', $output, $documented);
        preg_match('/CONTEXT_GATE_CANARY_EMAIL=(canary-[0-9a-f]{8}@example\.invalid)/', $output, $configured);

        $this->assertSame($documented[1] ?? null, $configured[1] ?? null);
        $this->assertNotNull($configured[1] ?? null);
        $this->assertStringContainsString('canaries.values', $output);
    }
}
