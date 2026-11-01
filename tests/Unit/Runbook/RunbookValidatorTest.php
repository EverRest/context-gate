<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Runbook;

use ContextGate\Runbook\RunbookContext;
use ContextGate\Runbook\RunbookStep;
use ContextGate\Runbook\RunbookValidator;
use ContextGate\Runbook\StepResult;
use ContextGate\Runbook\StepStatus;
use ContextGate\Tests\Fixtures\Runbook\OverLimitRunbook;
use ContextGate\Tests\Fixtures\Runbook\PassingRunbook;
use ContextGate\Tests\Fixtures\Runbook\ScriptedRunbook;
use ContextGate\Tests\TestCase;

/** spec 018 T022 (FR-021): each rule reports the runbook key and the rule; a valid runbook reports nothing. */
class RunbookValidatorTest extends TestCase
{
    private function step(string $name, bool $readOnly = true): RunbookStep
    {
        return new RunbookStep(
            name: $name,
            readOnly: $readOnly,
            run: fn (RunbookContext $context): StepResult => new StepResult(StepStatus::Ok, 'fine'),
        );
    }

    /**
     * Binds a scripted runbook under its class name, so the validator sees exactly these steps.
     *
     * @param list<RunbookStep> $steps
     */
    private function runbookWith(array $steps): string
    {
        app()->instance(ScriptedRunbook::class, new ScriptedRunbook($steps));

        return ScriptedRunbook::class;
    }

    /** @return list<string> the messages, each with its key first */
    private function messagesOf(mixed $class): array
    {
        return array_map(
            fn (array $v): string => "{$v['key']}: {$v['message']}",
            RunbookValidator::violations('disk', $class),
        );
    }

    public function test_a_missing_class_is_reported_with_the_key(): void
    {
        $this->assertSame(['disk: class does not exist'], $this->messagesOf('App\\Runbooks\\MissingRunbook'));
    }

    public function test_a_class_that_does_not_implement_runbook_is_reported(): void
    {
        $messages = $this->messagesOf(\stdClass::class);

        $this->assertCount(1, $messages);
        $this->assertStringStartsWith('disk: class must implement', $messages[0]);
    }

    public function test_zero_steps_is_reported(): void
    {
        $this->assertSame(['disk: has no steps'], $this->messagesOf($this->runbookWith([])));
    }

    public function test_twenty_one_steps_is_reported(): void
    {
        $steps = [];

        for ($i = 1; $i <= 21; $i++) {
            $steps[] = $this->step("s{$i}");
        }


        $this->assertSame(['disk: has 21 steps; the limit is 20'], $this->messagesOf($this->runbookWith($steps)));
    }

    public function test_a_duplicate_step_name_is_reported(): void
    {
        $messages = $this->messagesOf($this->runbookWith([$this->step('a'), $this->step('a')]));

        $this->assertSame(['disk: step name [a] is used more than once'], $messages);
    }

    public function test_a_step_not_declared_read_only_is_reported(): void
    {
        $messages = $this->messagesOf($this->runbookWith([$this->step('a', readOnly: false)]));

        $this->assertSame(['disk: step [a] is not declared read-only'], $messages);
    }

    public function test_a_timeout_above_the_cap_is_reported_through_the_step_error(): void
    {
        $messages = $this->messagesOf(OverLimitRunbook::class);

        $this->assertCount(1, $messages);
        $this->assertSame('disk: steps() threw InvalidArgumentException', $messages[0]); // class only, never the message (F6)
    }

    public function test_a_valid_runbook_reports_nothing(): void
    {
        $this->assertSame([], RunbookValidator::violations('passing', PassingRunbook::class));
    }
}
