<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Roles;

use ContextGate\Server\Fingerprint;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/** spec 010 US1 scenarios 1-2, SC-001: a token lists exactly its role's tools; a tool of another role is hidden. */
class RoleToolsHttpTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        Gate::define('echo.allow', fn (): bool => true);
        config(['context-gate.classification' => ['customers' => ['status' => 'public']]]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'echo' => ['enabled' => true, 'class' => EchoTool::class, 'gate' => 'echo.allow', 'fingerprint' => Fingerprint::of(new EchoTool())],
            'probe' => ['enabled' => true, 'class' => ProbeTool::class, 'gate' => 'echo.allow', 'fingerprint' => Fingerprint::of(new ProbeTool())],
        ]]);
        config(['context-gate.roles' => [
            'alpha' => ['tools' => ['echo'], 'ceiling' => 'internal'],
            'beta' => ['tools' => ['probe'], 'ceiling' => 'internal'],
        ]]);
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    protected function rpc(string $token, array $body): array
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ])->postJson('/'.config('context-gate.http.path'), $body);

        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_a_token_of_role_alpha_lists_only_alpha_tools_and_a_beta_tool_is_hidden(): void
    {
        $token = TokenFactory::create(['gate:read', 'gate:ceiling:internal', 'gate:role:alpha'])->plainTextToken;

        $list = $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $this->assertSame(['echo'], array_column($list['result']['tools'], 'name'));

        $hidden = $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'probe', 'arguments' => ['text' => 'x']]]);
        $unknown = $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'no_such_tool', 'arguments' => []]]);

        $this->assertSame($unknown, $hidden);
        $this->assertSame('policy.hidden', json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR)['reason']);
    }
}
