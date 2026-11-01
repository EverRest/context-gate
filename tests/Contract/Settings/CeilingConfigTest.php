<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;

/** Owner decision: a configured firewall.ceiling is public or internal only; personal and secret are config.invalid. */
class CeilingConfigTest extends TestCase
{
    use GateCallHarness;

    /** @return iterable<string, array{string}> */
    public static function refusedCeilings(): iterable
    {
        yield 'personal' => ['personal'];
        yield 'secret' => ['secret'];
    }

    /** @return iterable<string, array{string}> */
    public static function acceptedCeilings(): iterable
    {
        yield 'public' => ['public'];
        yield 'internal' => ['internal'];
    }

    #[DataProvider('refusedCeilings')]
    public function test_a_configured_ceiling_above_internal_refuses_the_call_with_config_invalid(string $ceiling): void
    {
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public', 'note' => 'internal', 'email' => 'personal']]);
        config(['context-gate.firewall.ceiling' => $ceiling]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertTrue($result['isError']);
        $this->assertFalse(ProbeTool::$called, 'the handler must not run for a refused ceiling');
        $this->assertSame('config.invalid', $this->lastReceipt()['reason']);
    }

    #[DataProvider('acceptedCeilings')]
    public function test_a_configured_ceiling_of_public_or_internal_leaves_the_call_unchanged(string $ceiling): void
    {
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public', 'note' => 'internal', 'email' => 'personal']]);
        config(['context-gate.firewall.ceiling' => $ceiling]);
        ProbeTool::$output = (string) json_encode([['id' => 1, 'status' => 'paid']]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertFalse($result['isError']);
        $this->assertTrue(ProbeTool::$called);
        $this->assertNotSame('config.invalid', $this->lastReceipt()['reason'] ?? null);
    }

    #[DataProvider('refusedCeilings')]
    public function test_gate_check_fails_and_names_the_ceiling(string $ceiling): void
    {
        config(['context-gate.firewall.ceiling' => $ceiling]);

        $exit = Artisan::call('gate:check');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('firewall.ceiling', Artisan::output());
    }

    #[DataProvider('acceptedCeilings')]
    public function test_gate_check_does_not_name_an_accepted_ceiling(string $ceiling): void
    {
        config(['context-gate.firewall.ceiling' => $ceiling]);

        Artisan::call('gate:check');

        $this->assertStringNotContainsString('firewall.ceiling', Artisan::output());
    }
}
