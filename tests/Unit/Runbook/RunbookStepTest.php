<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Runbook;

use ContextGate\Runbook\RunbookContext;
use ContextGate\Runbook\RunbookStep;
use ContextGate\Runbook\StepResult;
use ContextGate\Runbook\StepStatus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** spec 018 T012 (FR-001, FR-018, FR-016): a step's name, timeout and output size are validated when it is built. */
class RunbookStepTest extends TestCase
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

    public function test_a_valid_step_stores_what_it_was_given_and_the_defaults_are_2_5_seconds_and_512_bytes(): void
    {
        $step = new RunbookStep(name: 'queue_ok', readOnly: true, run: fn (RunbookContext $c): StepResult => new StepResult(StepStatus::Ok, ''));

        $this->assertSame('queue_ok', $step->name);
        $this->assertTrue($step->readOnly);
        $this->assertSame(2.5, $step->timeoutSeconds);
        $this->assertSame(512, $step->maxBytes);
    }

    public function test_readonly_is_stored_as_given(): void
    {
        $this->assertFalse($this->step(readOnly: false)->readOnly);
    }

    /** @return iterable<string, array{string}> */
    public static function badNames(): iterable
    {
        yield 'upper case' => ['Env'];
        yield 'leading dash' => ['-env'];
        yield 'space' => ['env step'];
        yield 'too long' => [str_repeat('a', 65)];
    }

    #[DataProvider('badNames')]
    public function test_a_step_name_outside_the_pattern_throws(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->step(name: $name);
    }

    /** @return iterable<string, array{float}> */
    public static function badTimeouts(): iterable
    {
        yield 'zero' => [0.0];
        yield 'above the cap' => [5.5];
    }

    #[DataProvider('badTimeouts')]
    public function test_a_timeout_of_zero_or_above_five_seconds_throws(float $timeout): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->step(timeout: $timeout);
    }

    public function test_a_timeout_at_the_cap_is_kept(): void
    {
        $this->assertSame(5.0, $this->step(timeout: 5.0)->timeoutSeconds);
    }

    /** @return iterable<string, array{int}> */
    public static function badSizes(): iterable
    {
        yield 'zero' => [0];
        yield 'above the cap' => [2049];
    }

    #[DataProvider('badSizes')]
    public function test_an_output_size_of_zero_or_above_2048_bytes_throws(int $bytes): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->step(bytes: $bytes);
    }

    public function test_an_output_size_at_the_cap_is_kept(): void
    {
        $this->assertSame(2048, $this->step(bytes: 2048)->maxBytes);
    }
}
