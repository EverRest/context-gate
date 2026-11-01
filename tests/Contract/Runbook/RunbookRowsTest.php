<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use ContextGate\Tests\Fixtures\Runbook\LargeRunbook;
use ContextGate\Tests\Fixtures\Runbook\MixedRunbook;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** spec 018 FR-012, FR-022, SC-005: MCP rows match the console statuses; text is untrusted; the receipt has no text. */
class RunbookRowsTest extends TestCase
{
    use RunbookToolHarness;

    public function test_the_rows_match_the_console_statuses_and_are_marked_untrusted(): void
    {
        $this->configureRunbookTool(MixedRunbook::class);

        $result = $this->rawCall('runbook.mixed')['result'];
        $rows = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($result['isError']);
        $this->assertSame(['ok_step', 'warn_step', 'fail_step'], array_column($rows, 'step'));
        $this->assertSame(['ok', 'warn', 'fail'], array_column($rows, 'status'));
        $this->assertSame(['untrusted' => true, 'source' => 'runbook.mixed', 'text' => 'first is ok'], $rows[0]['text']);

        config(['context-gate.runbooks' => ['mixed' => MixedRunbook::class]]);
        $this->assertSame(1, Artisan::call('gate:runbook', ['name' => 'mixed']));
    }

    public function test_the_receipt_holds_status_counts_and_no_row_text(): void
    {
        $this->configureRunbookTool(MixedRunbook::class);

        $this->rawCall('runbook.mixed');
        $receipt = $this->lastReceipt();

        $this->assertSame('runbook.mixed', $receipt['tool']);
        $this->assertSame(['ok' => 1, 'warn' => 1, 'fail' => 1], $receipt['status_counts']);
        $this->assertStringNotContainsString('first is ok', json_encode($receipt, JSON_THROW_ON_ERROR));
    }

    public function test_rows_that_overflow_the_report_cap_add_a_dropped_count_row(): void
    {
        $this->configureRunbookTool(LargeRunbook::class, 'large');

        $rows = json_decode($this->rawCall('runbook.large')['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $note = end($rows);

        $this->assertSame('report', $note['step']);
        $this->assertMatchesRegularExpression('/^\d+ rows dropped$/', $note['text']['text']);
        $this->assertLessThan(20, count($rows));
    }
}
