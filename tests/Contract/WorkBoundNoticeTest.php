<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;

/**
 * The work bound (twice the per-call limit, at most 10 000 rows) drops rows before the transforms. When the
 * transforms then shrink the rows, the final truncation keeps everything that is left, so the cut must still be
 * reported: the model is told the result is partial.
 */
class WorkBoundNoticeTest extends TestCase
{
    use GateCallHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public', 'note' => 'internal', 'email' => 'personal']]);
    }

    public function test_rows_dropped_by_the_work_bound_add_the_truncation_notice(): void
    {
        // A long personal value leaves as a short pseudonym: the rows shrink about thirtyfold after the transform.
        ProbeTool::$output = (string) json_encode(array_map(
            static fn (int $i): array => ['id' => $i, 'status' => 'paid', 'email' => str_repeat('a', 600)."{$i}@x.test"],
            range(1, 400),
        ));

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertFalse($result['isError']);
        $this->assertLessThan(400, $result['structuredContent']['rows']);
        $this->assertTrue($result['structuredContent']['truncated']);
        $this->assertSame('Notice: result truncated to the per-call limit; narrow the request.', $result['content'][1]['text'] ?? null);
    }

    public function test_rows_within_the_work_bound_add_no_notice(): void
    {
        ProbeTool::$output = (string) json_encode(array_map(
            static fn (int $i): array => ['id' => $i, 'status' => 'paid', 'email' => "{$i}@x.test"],
            range(1, 5),
        ));

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertFalse($result['structuredContent']['truncated']);
        $this->assertCount(1, $result['content']);
    }
}
