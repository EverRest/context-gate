<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\BucketDateContract;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;

/** F3: a bad value in a transform other than a pseudonym is transform.failed, not pseudonym.keyfail. */
class TransformFailureTest extends TestCase
{
    use GateCallHarness;

    public function test_a_non_numeric_bucket_value_is_transform_failed(): void
    {
        $this->assertTransformFailed(BucketDateContract::class, ['customers' => ['total' => 'internal', 'placed' => 'internal']], [['total' => 'many', 'placed' => '2026-01-02']]);
    }

    public function test_a_bad_date_string_is_transform_failed(): void
    {
        $this->assertTransformFailed(BucketDateContract::class, ['customers' => ['total' => 'internal', 'placed' => 'internal']], [['total' => 5, 'placed' => 'not a date']]);
    }

    public function test_a_missing_record_id_for_a_pseudonym_is_transform_failed(): void
    {
        $this->assertTransformFailed(ProbeContract::class, ['customers' => ['status' => 'internal', 'note' => 'internal', 'email' => 'internal']], [['status' => 'x', 'note' => 'y', 'email' => 'z']]);
    }

    /**
     * @param class-string<\ContextGate\Contract\ContextContract> $contract
     * @param array<string, array<string, string>> $classes
     * @param list<array<string, mixed>> $rows
     */
    private function assertTransformFailed(string $contract, array $classes, array $rows): void
    {
        $this->recordLines();
        $this->configureTool($contract, $classes);
        ProbeTool::$output = (string) json_encode($rows);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertTrue($result['isError']);
        $this->assertMatchesRegularExpression('/^Request failed\. Reference: r_[0-9a-f]{8}$/', $result['content'][0]['text']);
        $receipt = json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('transform.failed', $receipt['reason']);
    }
}
