<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\DayRelationProbeContract;
use ContextGate\Tests\Fixtures\DepthRelationProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\Fixtures\TextRelationProbeContract;
use ContextGate\Tests\TestCase;

/** A contract that relates to text, to a day or week pseudonym, or one level too deep is refused before the handler runs. */
class PreFetchContractRefusalTest extends TestCase
{
    use GateCallHarness;

    public function test_a_relation_to_a_text_field_is_refused_before_the_handler_runs(): void
    {
        $this->assertRefusedBeforeHandler(TextRelationProbeContract::class, [
            'orders' => ['customer_id' => 'internal'],
            'customers' => ['customer' => 'public', 'email' => 'internal'],
        ], 'policy.denied');
    }

    public function test_a_relation_deeper_than_one_level_is_refused_before_the_handler_runs(): void
    {
        $this->assertRefusedBeforeHandler(DepthRelationProbeContract::class, [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['name' => 'public'],
        ], 'policy.denied');
    }

    public function test_a_relation_to_a_day_scoped_pseudonym_is_refused_before_the_handler_runs(): void
    {
        $this->assertRefusedBeforeHandler(DayRelationProbeContract::class, [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['name' => 'personal'],
        ], 'pseudonym.unknown');
    }

    /**
     * @param class-string<\ContextGate\Contract\ContextContract> $contract
     * @param array<string, array<string, string>> $classes
     */
    private function assertRefusedBeforeHandler(string $contract, array $classes, string $reason): void
    {
        $this->recordLines();
        $this->configureTool($contract, $classes);
        ProbeTool::$output = '[]';

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertTrue($result['isError']);
        $this->assertSame('Request refused.', $result['content'][0]['text']);
        $this->assertFalse(ProbeTool::$called, 'the handler must not run for a refused contract');
        $receipt = json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($reason, $receipt['reason']);
    }
}
