<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Log;

/** Over HTTP there is no STDERR (PHP-FPM): boot diagnostics go to the receipt log channel, once per process. */
class HttpDiagnosticsTest extends TestCase
{
    public function test_the_canary_warning_is_logged_once_across_http_requests(): void
    {
        TokenFactory::migrate();
        config(['context-gate.enabled' => true, 'context-gate.canaries.values' => []]);
        $token = TokenFactory::create(['gate:read', 'gate:ceiling:internal'])->plainTextToken;
        $lines = [];
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        Log::shouldReceive('error')->andReturnNull();

        foreach ([1, 2] as $id) {
            $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'])
                ->postJson('/'.config('context-gate.http.path'), ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/list'])->assertOk();
        }

        $this->assertSame(['context-gate: context-gate.canaries.values is empty; canary protection is off.'], $lines);
    }
}
