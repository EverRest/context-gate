<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\ContextGateServiceProvider;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\TestCase;

/** SC-007: receipts show the rules in force, so a catalog change is visible and an unchanged rule set is stable. */
class RuleChangeTest extends TestCase
{
    public function test_changing_the_catalog_changes_the_classification_hash(): void
    {
        config(['context-gate.classification' => ['customers' => ['email' => 'personal']]]);
        $before = ContextGateServiceProvider::ruleHashes();

        config(['context-gate.classification' => ['customers' => ['email' => 'internal']]]);
        $after = ContextGateServiceProvider::ruleHashes();

        $this->assertNotSame($before['classification'], $after['classification']);
        $this->assertSame($before['tool'], $after['tool']);
        $this->assertSame($before['contract'], $after['contract']);
    }

    public function test_two_calls_without_changes_give_identical_hashes(): void
    {
        config(['context-gate.classification' => ['customers' => ['email' => 'personal', 'name' => 'internal']]]);
        config(['context-gate.tools' => [
            'echo' => ['enabled' => true, 'class' => EchoTool::class, 'gate' => 'echo.allow', 'fingerprint' => ''],
        ]]);

        $this->assertSame(ContextGateServiceProvider::ruleHashes(), ContextGateServiceProvider::ruleHashes());
    }

    public function test_classification_order_does_not_change_the_hash(): void
    {
        config(['context-gate.classification' => ['customers' => ['email' => 'personal', 'name' => 'internal']]]);
        $first = ContextGateServiceProvider::ruleHashes();

        config(['context-gate.classification' => ['customers' => ['name' => 'internal', 'email' => 'personal']]]);

        $this->assertSame($first, ContextGateServiceProvider::ruleHashes());
    }
}
