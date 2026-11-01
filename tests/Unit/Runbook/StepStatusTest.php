<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Runbook;

use ContextGate\Runbook\StepStatus;
use PHPUnit\Framework\TestCase;

/** spec 018 T008 (FR-008, FR-014): the five statuses and their exit codes. */
class StepStatusTest extends TestCase
{
    public function test_the_enum_has_exactly_the_five_statuses(): void
    {
        $this->assertSame(['ok', 'warn', 'fail', 'timeout', 'error'], array_map(
            fn (StepStatus $status): string => $status->value,
            StepStatus::cases(),
        ));
    }

    public function test_ok_and_warn_exit_zero_and_the_rest_exit_one(): void
    {
        $this->assertSame(0, StepStatus::Ok->exitCode());
        $this->assertSame(0, StepStatus::Warn->exitCode());
        $this->assertSame(1, StepStatus::Fail->exitCode());
        $this->assertSame(1, StepStatus::Timeout->exitCode());
        $this->assertSame(1, StepStatus::Error->exitCode());
    }
}
