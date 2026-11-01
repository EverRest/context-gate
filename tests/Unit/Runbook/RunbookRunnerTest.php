<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Runbook;

use ContextGate\Runbook\RunbookContext;
use ContextGate\Runbook\RunbookRunner;
use ContextGate\Runbook\RunbookStep;
use ContextGate\Runbook\StepResult;
use ContextGate\Runbook\StepStatus;
use ContextGate\Tests\Fixtures\Runbook\ScriptedRunbook;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/** spec 018 T016 (FR-016, FR-017, R1, R2): order, error mapping, timeouts, output cut and the run budget. */
class RunbookRunnerTest extends TestCase
{
    /** @var list<float> one-element holder so steps can advance the fake clock */
    private array $time = [0.0];

    private function runner(?callable $onError = null): RunbookRunner
    {
        return new RunbookRunner(
            clock: fn (): float => $this->time[0],
            onError: $onError === null ? null : $onError(...),
        );
    }

    private function step(string $name, callable $body, float $timeout = 2.5, int $bytes = 512): RunbookStep
    {
        return new RunbookStep(
            name: $name,
            readOnly: true,
            run: fn (RunbookContext $context): StepResult => $body($context),
            timeoutSeconds: $timeout,
            maxBytes: $bytes,
        );
    }

    private function ok(string $text = 'fine'): callable
    {
        return fn (): StepResult => new StepResult(StepStatus::Ok, $text);
    }

    public function test_steps_run_in_declared_order_and_rows_keep_that_order(): void
    {
        $order = [];
        $runbook = new ScriptedRunbook([
            $this->step('first', function () use (&$order): StepResult {
                $order[] = 'first';
                return new StepResult(StepStatus::Ok, 'a');
            }),
            $this->step('second', function () use (&$order): StepResult {
                $order[] = 'second';
                return new StepResult(StepStatus::Warn, 'b');
            }),
        ]);

        $rows = $this->runner()->run($runbook, new RunbookContext());

        $this->assertSame(['first', 'second'], $order);
        $this->assertSame(['first', 'second'], array_column($rows, 'step'));
        $this->assertSame(StepStatus::Warn, $rows[1]['result']->status);
    }

    public function test_a_throwing_step_is_error_with_the_generic_text_and_the_others_still_run(): void
    {
        $reported = [];
        $runbook = new ScriptedRunbook([
            $this->step('boom', fn (): StepResult => throw new RuntimeException('secret-value-in-message')),
            $this->step('missing', function (RunbookContext $context): StepResult {
                return $context->noSuchCheck(); // @phpstan-ignore method.notFound (deliberate: a step calling a missing method)
            }),
            $this->step('after', $this->ok('still runs')),
        ]);

        $rows = $this->runner(function (string $step, Throwable $e) use (&$reported): void {
            $reported[] = [$step, $e::class];
        })->run($runbook, new RunbookContext());

        $this->assertSame(StepStatus::Error, $rows[0]['result']->status);
        $this->assertSame('Step failed. See the operator note.', $rows[0]['result']->text);
        $this->assertSame(StepStatus::Error, $rows[1]['result']->status);
        $this->assertSame(StepStatus::Ok, $rows[2]['result']->status);
        $this->assertSame([['boom', RuntimeException::class], ['missing', \Error::class]], $reported);
    }

    public function test_a_step_that_returns_after_its_timeout_is_timeout_and_its_text_is_discarded(): void
    {
        $runbook = new ScriptedRunbook([
            $this->step('slow', function (): StepResult {
                $this->time[0] += 3.0;

                return new StepResult(StepStatus::Ok, 'late value');
            }, timeout: 2.5),
        ]);

        $row = $this->runner()->run($runbook, new RunbookContext())[0]['result'];

        $this->assertSame(StepStatus::Timeout, $row->status);
        $this->assertSame('Step returned after its time limit; text discarded.', $row->text);
    }

    public function test_a_text_longer_than_its_limit_is_cut_and_marked(): void
    {
        $runbook = new ScriptedRunbook([$this->step('long', $this->ok(str_repeat('x', 100)), bytes: 20)]);

        $text = $this->runner()->run($runbook, new RunbookContext())[0]['result']->text;

        $this->assertLessThanOrEqual(20, strlen($text));
        $this->assertStringEndsWith(' [cut]', $text);
    }

    public function test_once_the_run_budget_is_used_the_remaining_steps_are_timeout_and_do_not_run(): void
    {
        $ran = [];
        $steps = [];

        for ($i = 1; $i <= 7; $i++) {
            $steps[] = $this->step("s{$i}", function () use (&$ran, $i): StepResult {
                $ran[] = $i;
                $this->time[0] += 5.0;

                return new StepResult(StepStatus::Ok, 'ok');
            }, timeout: 5.0);
        }

        $rows = $this->runner()->run(new ScriptedRunbook($steps), new RunbookContext());

        $this->assertSame([1, 2, 3, 4, 5, 6], $ran);
        $this->assertSame(StepStatus::Timeout, $rows[6]['result']->status);
        $this->assertSame('Not run: run limit reached.', $rows[6]['result']->text);
    }
}
