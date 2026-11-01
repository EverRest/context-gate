<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/** FR-016 for a refused preview: a call with an empty tool name still writes a receipt marked source preview. */
class PreviewEmptyNameReceiptTest extends TestCase
{
    public function test_refused_preview_without_a_tool_name_writes_a_receipt_marked_as_preview(): void
    {
        $lines = [];
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        app()->instance('context-gate.caller', 'alice');

        Artisan::call('gate:preview', ['tool' => '']);

        $refusals = array_values(array_filter(
            array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines),
            static fn (array $record): bool => ($record['reason'] ?? null) === 'input.invalid',
        ));

        $this->assertNotEmpty($refusals);
        $this->assertSame('preview', $refusals[0]['source'] ?? null);
    }
}
