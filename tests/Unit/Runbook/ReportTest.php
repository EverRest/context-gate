<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Runbook;

use ContextGate\Pipeline\Caps;
use ContextGate\Runbook\Report;
use ContextGate\Runbook\StepResult;
use ContextGate\Runbook\StepStatus;
use PHPUnit\Framework\TestCase;

/** spec 018 T018 (FR-016, spec edge cases): the report keeps whole rows in order and never exceeds 8 KiB. */
class ReportTest extends TestCase
{
    /** @return list<array{step: string, result: StepResult}> */
    private function results(string ...$texts): array
    {
        $rows = [];

        foreach ($texts as $i => $text) {
            $rows[] = ['step' => "s{$i}", 'result' => new StepResult(StepStatus::Ok, $text)];
        }

        return $rows;
    }

    public function test_rows_that_fit_are_kept_in_order(): void
    {
        $report = Report::fromResults($this->results('one', 'two', 'three'));

        $this->assertSame(['s0', 's1', 's2'], array_column($report->rows, 'step'));
        $this->assertSame(['ok', 'ok', 'ok'], array_column($report->rows, 'status'));
        $this->assertSame(0, $report->droppedRows);
    }

    public function test_a_row_that_does_not_fit_is_dropped_whole_and_counted(): void
    {
        $report = Report::fromResults($this->results(str_repeat('a', 5000), str_repeat('b', 5000), 'small'));

        $this->assertSame(['s0', 's2'], array_column($report->rows, 'step'));
        $this->assertSame(1, $report->droppedRows);
    }

    public function test_no_row_text_is_cut(): void
    {
        $text = str_repeat('word ', 1000);

        $report = Report::fromResults($this->results($text));

        $this->assertSame($text, $report->rows[0]['text']);
    }

    public function test_the_size_never_exceeds_the_report_cap(): void
    {
        $texts = array_fill(0, 100, str_repeat('x', 300));

        $report = Report::fromResults($this->results(...$texts));

        $this->assertLessThanOrEqual(Caps::RUNBOOK_REPORT_BYTES, $report->bytes);
        $this->assertSame(100, count($report->rows) + $report->droppedRows);
    }
}
