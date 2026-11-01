<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;

/** spec 007 T034 (US5, SC-005, FR-007), characterization of spec 001 receipts: no argument or result value reaches the log. */
class LogPrivacyTest extends TestCase
{
    use GateCallHarness;

    private const SENTINEL_ARGUMENT = 'FAKE_FIXTURE_ONLY_ARGUMENT_SENTINEL';

    private const SENTINEL_RESULT = 'FAKE_FIXTURE_ONLY_RESULT_SENTINEL';

    public function test_receipt_lines_hold_no_argument_or_result_value(): void
    {
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public', 'note' => 'internal', 'email' => 'personal']]);
        ProbeTool::$output = (string) json_encode([['id' => 1, 'status' => self::SENTINEL_RESULT]]);

        $result = $this->callTool('probe', ['text' => self::SENTINEL_ARGUMENT]);

        $this->assertFalse($result['isError']);
        $this->assertStringContainsString(self::SENTINEL_RESULT, (string) $result['content'][0]['text'], 'the sentinel result reaches the caller, not the log');
        $this->assertNotEmpty($this->lines, 'a receipt line must be written');
        $this->assertArrayHasKey('decision', json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR));

        foreach ($this->lines as $line) {
            $this->assertStringNotContainsString(self::SENTINEL_ARGUMENT, $line);
            $this->assertStringNotContainsString(self::SENTINEL_RESULT, $line);
        }
    }
}
