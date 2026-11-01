<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Runbook;

use ContextGate\Runbook\RunbookContext;
use ContextGate\Runbook\RunbookFingerprint;
use ContextGate\Runbook\RunbookStep;
use ContextGate\Runbook\StepResult;
use ContextGate\Runbook\StepStatus;
use ContextGate\Tests\Fixtures\Runbook\ScriptedRunbook;
use PHPUnit\Framework\TestCase;

/** spec 018 T020 (SC-006, FR-020, R6): the fingerprint covers name, description and each step, in order. */
class RunbookFingerprintTest extends TestCase
{
    private function step(string $name = 'env_app_key', bool $readOnly = true, float $timeout = 2.5, int $bytes = 512): RunbookStep
    {
        return new RunbookStep(
            name: $name,
            readOnly: $readOnly,
            run: fn (RunbookContext $context): StepResult => new StepResult(StepStatus::Ok, 'fine'),
            timeoutSeconds: $timeout,
            maxBytes: $bytes,
        );
    }

    /** @param list<RunbookStep> $steps */
    private function fingerprint(array $steps, string $name = 'disk', string $description = 'Disk checks.'): string
    {
        return RunbookFingerprint::of(new ScriptedRunbook($steps, $name, $description));
    }

    public function test_two_computations_on_the_same_runbook_are_equal(): void
    {
        $steps = [$this->step('a'), $this->step('b')];

        $this->assertSame($this->fingerprint($steps), $this->fingerprint($steps));
        $this->assertSame(64, strlen($this->fingerprint($steps)));
    }

    public function test_a_change_to_the_name_or_description_changes_the_value(): void
    {
        $steps = [$this->step('a')];
        $base = $this->fingerprint($steps);

        $this->assertNotSame($base, $this->fingerprint($steps, name: 'queue'));
        $this->assertNotSame($base, $this->fingerprint($steps, description: 'Other.'));
    }

    public function test_a_change_to_a_step_changes_the_value(): void
    {
        $base = $this->fingerprint([$this->step('a')]);

        $this->assertNotSame($base, $this->fingerprint([$this->step('b')]), 'step name');
        $this->assertNotSame($base, $this->fingerprint([$this->step('a', readOnly: false)]), 'readOnly');
        $this->assertNotSame($base, $this->fingerprint([$this->step('a', timeout: 1.0)]), 'timeout');
        $this->assertNotSame($base, $this->fingerprint([$this->step('a', bytes: 100)]), 'output size');
    }

    public function test_adding_removing_or_reordering_steps_changes_the_value(): void
    {
        $a = $this->step('a');
        $b = $this->step('b');
        $base = $this->fingerprint([$a, $b]);

        $this->assertNotSame($base, $this->fingerprint([$a, $b, $this->step('c')]), 'added');
        $this->assertNotSame($base, $this->fingerprint([$a]), 'removed');
        $this->assertNotSame($base, $this->fingerprint([$b, $a]), 'reordered');
    }
}
