<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\SourceTools;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

/** An empty path passes the schema but not the source: it answers like any other input.invalid, as a JSON-RPC error. */
class SourceInputInvalidTest extends TestCase
{
    use GateCallHarness;

    public function test_an_empty_docs_path_is_a_json_rpc_input_error_with_an_input_invalid_receipt(): void
    {
        Gate::define('source.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['docs.read']]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'docs.read' => ['enabled' => true, 'gate' => 'source.allow'] + SourceTools::entries()['docs.read'],
        ]]);
        $this->recordLines();

        $raw = $this->send((string) json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'docs.read', 'arguments' => ['path' => '']],
        ], JSON_THROW_ON_ERROR))[0];

        $error = json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['error'];
        $this->assertSame(-32602, $error['code']);
        $this->assertSame('input.invalid', json_decode($this->lines[0], true, 512, JSON_THROW_ON_ERROR)['reason']);
    }
}
