<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\ContextGateServiceProvider;
use ContextGate\Tests\Fixtures\CustomerContract;
use ContextGate\Tests\Fixtures\DayPseudonymCustomerContract;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\Fixtures\HiddenTool;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** US1 scenarios 1-2, SC-001, FR-001, FR-002: enabled tools in full, disabled tools by name only. */
class AuditListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['context-gate.classification' => [
            'customers' => ['customer' => 'internal', 'email' => 'internal', 'name' => 'personal'],
        ]]);
        config(['context-gate.tools' => [
            'probe' => ['enabled' => true, 'class' => ProbeTool::class, 'contract' => CustomerContract::class, 'risk' => 'low'],
            'echo' => ['enabled' => true, 'class' => EchoTool::class, 'contract' => DayPseudonymCustomerContract::class, 'risk' => 'high'],
            'secret' => ['enabled' => false, 'class' => HiddenTool::class, 'contract' => CustomerContract::class, 'risk' => 'medium'],
        ]]);
    }

    public function test_enabled_tools_are_listed_with_risk_ceiling_classes_contract_and_hashes(): void
    {
        $this->assertSame(0, Artisan::call('gate:audit'));
        $output = Artisan::output();
        $hashes = ContextGateServiceProvider::ruleHashes();

        $this->assertStringContainsString("probe\n", $output);
        $this->assertStringContainsString('risk: low', $output);
        $this->assertStringContainsString('ceiling: internal', $output);
        $this->assertStringContainsString('field classes: internal=2', $output);
        $this->assertStringContainsString('contract: '.CustomerContract::class, $output);

        $this->assertStringContainsString("echo\n", $output);
        $this->assertStringContainsString('risk: high', $output);
        $this->assertStringContainsString('field classes: personal=1', $output);
        $this->assertStringContainsString('contract: '.DayPseudonymCustomerContract::class, $output);

        foreach ($hashes as $hash) {
            $this->assertMatchesRegularExpression('/[0-9a-f]{6}/', $hash);
        }
        $this->assertStringContainsString('tool='.$hashes['tool'], $output);
        $this->assertStringContainsString('contract='.$hashes['contract'], $output);
        $this->assertStringContainsString('classification='.$hashes['classification'], $output);
    }

    public function test_disabled_tool_appears_only_under_not_enabled(): void
    {
        Artisan::call('gate:audit');
        $output = Artisan::output();

        [$enabledPart, $notEnabled] = explode('Not enabled:', $output, 2);

        $this->assertStringNotContainsString('secret', $enabledPart);
        $this->assertSame("\n  secret\n", $notEnabled);
    }
}
