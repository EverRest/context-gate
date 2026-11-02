<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Classification\Ceiling;
use ContextGate\Settings\RuleHashes;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\TestCase;

/** SC-007: receipts show the rules in force, so a catalog change is visible and an unchanged rule set is stable. */
class RuleChangeTest extends TestCase
{
    public function test_changing_the_catalog_changes_the_classification_hash(): void
    {
        config(['context-gate.classification' => ['customers' => ['email' => 'personal']]]);
        $before = RuleHashes::forCall(Ceiling::Internal);

        config(['context-gate.classification' => ['customers' => ['email' => 'internal']]]);
        $after = RuleHashes::forCall(Ceiling::Internal);

        $this->assertNotSame($before['classification'], $after['classification']);
        $this->assertSame($before['tool'], $after['tool']);
        $this->assertSame($before['contract'], $after['contract']);
    }

    public function test_switching_the_security_profile_changes_the_classification_hash(): void
    {
        config(['app.env' => 'testing', 'context-gate.profile' => 'strict']);
        $before = RuleHashes::forCall(Ceiling::Internal);

        config(['context-gate.profile' => 'balanced']);

        $this->assertNotSame($before['classification'], RuleHashes::forCall(Ceiling::Internal)['classification']);
    }

    public function test_two_calls_without_changes_give_identical_hashes(): void
    {
        config(['context-gate.classification' => ['customers' => ['email' => 'personal', 'name' => 'internal']]]);
        config(['context-gate.tools' => [
            'echo' => ['enabled' => true, 'class' => EchoTool::class, 'gate' => 'echo.allow', 'fingerprint' => ''],
        ]]);

        $this->assertSame(RuleHashes::forCall(Ceiling::Internal), RuleHashes::forCall(Ceiling::Internal));
    }

    public function test_classification_order_does_not_change_the_hash(): void
    {
        config(['context-gate.classification' => ['customers' => ['email' => 'personal', 'name' => 'internal']]]);
        $first = RuleHashes::forCall(Ceiling::Internal);

        config(['context-gate.classification' => ['customers' => ['name' => 'internal', 'email' => 'personal']]]);

        $this->assertSame($first, RuleHashes::forCall(Ceiling::Internal));
    }
}
