<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\Fingerprint;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

/** context-gate.enabled is the master switch: false lists nothing and answers every call like an unknown tool. */
class EnabledFlagTest extends TestCase
{
    use GateCallHarness;

    private function setUpTool(bool $enabled): void
    {
        Gate::define('echo.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['echo']]);
        config(['context-gate.enabled' => $enabled, 'context-gate.tools' => [
            'echo' => ['enabled' => true, 'class' => EchoTool::class, 'gate' => 'echo.allow', 'fingerprint' => Fingerprint::of(new EchoTool())],
        ]]);
    }

    public function test_disabled_lists_nothing_and_call_is_tool_not_found_with_hidden_receipt(): void
    {
        $this->setUpTool(false);
        $this->recordLines();

        $list = json_decode($this->send('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')[0], true, 512, JSON_THROW_ON_ERROR);
        $call = json_decode($this->send('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"echo","arguments":{"text":"x"}}}')[0], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([], $list['result']['tools']);
        $this->assertSame(['code' => -32602, 'message' => 'Tool not found.'], $call['error']);
        $this->assertSame('policy.hidden', $this->lastReceipt()['reason']);
        $this->assertNull($this->lastReceipt()['tool']);
    }

    public function test_enabled_lists_and_serves_the_tool(): void
    {
        $this->setUpTool(true);
        $list = json_decode($this->send('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')[0], true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(1, $list['result']['tools']);
    }

    public function test_http_disabled_lists_nothing_and_enabled_lists_the_tool(): void
    {
        TokenFactory::migrate();
        $this->setUpTool(false);
        $token = TokenFactory::create(['gate:read', 'gate:ceiling:internal']);
        config(['context-gate.callers.token:'.$token->accessToken->getKey().'.tools' => ['echo']]);
        $post = fn (): array => json_decode((string) $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ])->postJson('/'.config('context-gate.http.path'), ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([], $post()['result']['tools']);
        config(['context-gate.enabled' => true]);
        $this->assertCount(1, $post()['result']['tools']);
    }
}
