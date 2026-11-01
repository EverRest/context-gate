<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use ContextGate\Tests\Fixtures\Runbook\CanaryRunbook;
use ContextGate\Tests\TestCase;

/** spec 018 FR-010, SC-004: a canary value in a step text blocks the MCP response and records canary.hit. */
class RunbookCanaryBlockTest extends TestCase
{
    use RunbookToolHarness;

    public function test_a_canary_in_a_row_blocks_the_response_and_records_canary_hit(): void
    {
        $this->configureRunbookTool(CanaryRunbook::class, 'canary');
        config(['context-gate.canaries.values' => ['FAKE_FIXTURE_ONLY_CANARY']]);

        $result = $this->rawCall('runbook.canary')['result'];

        $this->assertTrue($result['isError']);
        $this->assertStringStartsWith('Request failed. Reference: ', $result['content'][0]['text']);
        $this->assertStringNotContainsString('FAKE_FIXTURE_ONLY_CANARY', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame('canary.hit', $this->lastReceipt()['reason']);
    }
}
