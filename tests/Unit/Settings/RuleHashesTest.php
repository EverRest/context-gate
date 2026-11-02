<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Classification\Ceiling;
use ContextGate\Settings\BuiltinClassification;
use ContextGate\Settings\RuleHashes;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;

/** spec 009 US4, FR-012, FR-013, SC-006: receipt rule hashes cover the effective values of the call. */
class RuleHashesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['context-gate.tools' => ['probe' => ['enabled' => true, 'class' => ProbeTool::class, 'contract' => ProbeContract::class, 'gate' => 'x', 'fingerprint' => null]]]);
        config(['context-gate.classification' => ['customers' => ['status' => 'public', 'note' => 'internal', 'email' => 'personal']]]);
        config(['app.env' => 'local']);
    }

    public function test_tool_and_contract_hashes_are_the_values_the_package_computed_before_this_spec(): void
    {
        $hashes = RuleHashes::forCall(Ceiling::Internal);

        $this->assertSame('3a7d51', $hashes['tool']);
        $this->assertSame('3b6717', $hashes['contract']);
    }

    public function test_the_classification_hash_differs_between_strict_and_balanced(): void
    {
        config(['context-gate.profile' => 'strict']);
        $strict = RuleHashes::forCall(Ceiling::Internal)['classification'];

        config(['context-gate.profile' => 'balanced']);
        $balanced = RuleHashes::forCall(Ceiling::Internal)['classification'];

        $this->assertNotSame($strict, $balanced);
    }

    public function test_the_classification_hash_differs_between_the_public_and_internal_ceilings(): void
    {
        $this->assertNotSame(RuleHashes::forCall(Ceiling::Public)['classification'], RuleHashes::forCall(Ceiling::Internal)['classification']);
    }

    public function test_a_built_in_table_repeated_exactly_hashes_the_same_as_one_omitted(): void
    {
        config(['context-gate.classification' => ['customers' => ['status' => 'public', 'note' => 'internal', 'email' => 'personal']]]);
        $omitted = RuleHashes::forCall(Ceiling::Internal)['classification'];

        config(['context-gate.classification' => BuiltinClassification::TABLES + ['customers' => ['status' => 'public', 'note' => 'internal', 'email' => 'personal']]]);
        $repeated = RuleHashes::forCall(Ceiling::Internal)['classification'];

        $this->assertSame($omitted, $repeated);
    }

    public function test_raw_configs_with_equal_effective_values_hash_the_same(): void
    {
        config(['context-gate.firewall.limits.per_call_bytes' => null]);
        $implicit = RuleHashes::forCall(Ceiling::Internal)['classification'];

        config(['context-gate.firewall.limits.per_call_bytes' => 16384]);
        $explicit = RuleHashes::forCall(Ceiling::Internal)['classification'];

        $this->assertSame($implicit, $explicit);
    }
}
