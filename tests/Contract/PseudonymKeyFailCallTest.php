<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\DayPseudonymCustomerContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

/** F3 and item 4: a pseudonym key failure is pseudonym.keyfail, answered with the failed reference, not a refusal. */
class PseudonymKeyFailCallTest extends TestCase
{
    use GateCallHarness;

    public function test_an_unreadable_pseudonym_state_fails_with_a_reference_and_keyfail(): void
    {
        $this->recordLines();
        $this->configureTool(DayPseudonymCustomerContract::class, ['customers' => ['name' => 'personal']]);
        // The day state for this caller and tool is stored, but it is not a valid encrypted value.
        Cache::put('context-gate:pseudonym:day:alice:probe', 'not-an-encrypted-state', 600);
        ProbeTool::$output = (string) json_encode([['id' => 5, 'name' => 'Ann']]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertTrue($result['isError']);
        $this->assertMatchesRegularExpression('/^Request failed\. Reference: r_[0-9a-f]{8}$/', $result['content'][0]['text']);
        $receipt = json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pseudonym.keyfail', $receipt['reason']);
        $this->assertSame('failed', $receipt['decision']);
    }
}
