<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness\Checks;

use ContextGate\Classification\Catalog;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\Checks\RunbookCheck;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Runbook\RunbookFingerprint;
use ContextGate\Tests\Fixtures\Runbook\MixedRunbook;
use ContextGate\Tests\Fixtures\Runbook\TooManyStepsRunbook;
use ContextGate\Tests\Fixtures\Runbook\UnsafeRunbook;
use ContextGate\Tests\TestCase;

/** spec 018 FR-021, US5: gate:check gives one result per runbook and names the key and the rule. */
class RunbookCheckTest extends TestCase
{
    /**
     * @param array<string, string> $runbooks
     * @param array<string, mixed> $tools
     * @return array<string, array{status: CheckStatus, message: string, fix: string}> result by subject
     */
    private function results(array $runbooks, array $tools = []): array
    {
        $ctx = new ReadinessContext(['runbooks' => $runbooks, 'tools' => $tools], new Catalog([]), []);
        $results = [];

        foreach ((new RunbookCheck())->results($ctx) as $result) {
            $results[$result->subject] = ['status' => $result->status, 'message' => $result->message, 'fix' => $result->fix];
        }

        return $results;
    }

    public function test_a_valid_runbook_passes(): void
    {
        $results = $this->results(['mixed' => MixedRunbook::class]);

        $this->assertSame(CheckStatus::Pass, $results['runbooks.mixed']['status']);
    }

    public function test_a_missing_class_fails_and_is_named_by_its_key(): void
    {
        $results = $this->results(['ghost' => 'App\\Runbooks\\Missing']);

        $this->assertSame(CheckStatus::Fail, $results['runbooks.ghost']['status']);
        $this->assertStringContainsString('class does not exist', $results['runbooks.ghost']['message']);
    }

    public function test_more_than_twenty_steps_fails_and_names_the_runbook(): void
    {
        $results = $this->results(['too-many' => TooManyStepsRunbook::class]);

        $this->assertSame(CheckStatus::Fail, $results['runbooks.too-many']['status']);
        $this->assertStringContainsString('21 steps', $results['runbooks.too-many']['message']);
    }

    public function test_a_step_not_declared_read_only_fails(): void
    {
        $results = $this->results(['unsafe' => UnsafeRunbook::class]);

        $this->assertSame(CheckStatus::Fail, $results['runbooks.unsafe']['status']);
        $this->assertStringContainsString('not declared read-only', $results['runbooks.unsafe']['message']);
    }

    public function test_a_case_only_duplicate_name_fails_both_keys(): void
    {
        $results = $this->results(['mixed' => MixedRunbook::class, 'Mixed' => MixedRunbook::class]);

        $this->assertSame(CheckStatus::Fail, $results['runbooks.mixed']['status']);
        $this->assertStringContainsString('case-insensitive', $results['runbooks.mixed']['message']);
    }

    public function test_a_fingerprint_that_differs_from_the_approved_value_fails_and_names_the_tool(): void
    {
        $results = $this->results(
            ['mixed' => MixedRunbook::class],
            ['runbook.mixed' => ['enabled' => true, 'fingerprint' => 'not-the-approved-value']],
        );

        $this->assertSame(CheckStatus::Fail, $results['runbook.mixed']['status']);
        $this->assertStringContainsString('gate:fingerprint runbook.mixed', $results['runbook.mixed']['fix']);
    }

    public function test_an_approved_fingerprint_passes(): void
    {
        $results = $this->results(
            ['mixed' => MixedRunbook::class],
            ['runbook.mixed' => ['enabled' => true, 'fingerprint' => RunbookFingerprint::of(new MixedRunbook())]],
        );

        $this->assertSame(CheckStatus::Pass, $results['runbook.mixed']['status']);
    }
}
