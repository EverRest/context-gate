<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** T042, User Story 4 scenario 3 and FR-016: a preview writes a receipt with source preview and no value. */
class PreviewReceiptTest extends TestCase
{
    use GateCallHarness;

    public function test_preview_writes_a_receipt_with_source_preview_and_no_value(): void
    {
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['id' => 'public', 'status' => 'public', 'email' => 'personal']]);
        ProbeTool::$output = (string) json_encode([['id' => 42, 'status' => 'paid', 'email' => 'jane@example.invalid']]);

        Artisan::call('gate:preview', ['tool' => 'probe', '--args' => '{"text":"x"}']);

        $records = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $this->lines);
        $preview = array_values(array_filter($records, static fn (array $record): bool => ($record['source'] ?? null) === 'preview'));

        $this->assertNotEmpty($preview);
        $this->assertSame('probe', $preview[0]['tool']);
        $this->assertSame('alice', $preview[0]['caller']);
        $this->assertStringNotContainsString('jane@example.invalid', implode("\n", $this->lines));
    }
}
