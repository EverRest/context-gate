<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\ReasonCode;
use ContextGate\Server\Receipt;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

class ReceiptTest extends TestCase
{
    public function test_writes_one_json_line_with_metadata_keys_only(): void
    {
        $id = null;
        $lines = $this->capture(function () use (&$id): void {
            $id = Receipt::write('alice', 'echo', ReasonCode::InputInvalid);
        });

        $this->assertCount(1, $lines);
        $this->assertStringNotContainsString("\n", $lines[0]);

        $record = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['id', 'at', 'caller', 'tool', 'decision', 'reason', 'rules'], array_keys($record));
        $this->assertMatchesRegularExpression('/^r_[0-9a-f]{8}$/', $record['id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $record['at']);
        $this->assertSame($id, $record['id']);
        $this->assertSame('alice', $record['caller']);
        $this->assertSame('echo', $record['tool']);
        $this->assertSame('invalid', $record['decision']);
        $this->assertSame('input.invalid', $record['reason']);
        $this->assertSame([], $record['rules']);
    }

    public function test_sentinel_caller_and_tool_only_appear_as_their_own_fields(): void
    {
        $lines = $this->capture(function (): void {
            Receipt::write('SENTINEL_CALLER', 'SENTINEL_TOOL', ReasonCode::PolicyHidden);
        });

        $record = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['id', 'at', 'caller', 'tool', 'decision', 'reason', 'rules'], array_keys($record));
        $this->assertSame('SENTINEL_CALLER', $record['caller']);
        $this->assertSame('SENTINEL_TOOL', $record['tool']);
        $this->assertSame('hidden', $record['decision']);
    }

    #[DataProvider('reasonCases')]
    public function test_receipt_carries_decision_and_reason_for_every_code(ReasonCode $reason): void
    {
        $lines = $this->capture(function () use ($reason): void {
            Receipt::write('alice', 'echo', $reason);
        });

        $record = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($reason->decision(), $record['decision']);
        $this->assertSame($reason->value, $record['reason']);
    }

    public function test_successful_call_is_allowed_with_null_reason(): void
    {
        $lines = $this->capture(function (): void {
            Receipt::write('alice', null);
        });

        $record = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('allow', $record['decision']);
        $this->assertNull($record['reason']);
        $this->assertNull($record['tool']);
    }

    /** @return iterable<string, array{ReasonCode}> */
    public static function reasonCases(): iterable
    {
        foreach (ReasonCode::cases() as $reason) {
            yield $reason->name => [$reason];
        }
    }

    /** @return list<string> */
    private function capture(callable $write): array
    {
        $lines = [];
        Log::shouldReceive('channel')->once()->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->once()->with(Mockery::on(function (string $line) use (&$lines): bool {
            $lines[] = $line;

            return true;
        }));

        $write();

        return $lines;
    }
}
