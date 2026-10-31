<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Log;

/** F2: a name that is not a registry tool never reaches the receipt or the response. */
class UnknownToolNameReceiptTest extends TestCase
{
    use GateCallHarness;

    public function test_an_unknown_tool_name_is_not_written_to_the_receipt_or_the_response(): void
    {
        $this->recordLines();
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['echo']]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'echo' => ['enabled' => true, 'class' => EchoTool::class, 'gate' => 'echo.allow', 'fingerprint' => hash('sha256', (new EchoTool())->description())],
        ]]);
        $seeded = 'SEEDED_NAME_9b2d4e';

        $response = $this->send((string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => $seeded, 'arguments' => []],
        ], JSON_THROW_ON_ERROR))[0];

        $this->assertStringNotContainsString($seeded, $response);
        $this->assertCount(1, $this->lines);
        $this->assertStringNotContainsString($seeded, $this->lines[0]);
        $record = json_decode($this->lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertNull($record['tool']);
        $this->assertSame('policy.hidden', $record['reason']);
    }

    private function recordLines(): void
    {
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
    }
}
