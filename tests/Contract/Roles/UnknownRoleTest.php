<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Roles;

use ContextGate\Server\Fingerprint;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/** spec 010 FR-010, US3, SC-003: a caller whose role is missing or not defined sees no tools and every call is unknown. */
class UnknownRoleTest extends TestCase
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
        ]]);
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
    }

    /** @return array<string, mixed> the JSON-RPC body of one POST */
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

    /** @return array<string, mixed> */
    protected function lastReceipt(): array
    {
        return json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{string}> */
    public static function tokens(): iterable
    {
        yield 'a role that is not defined' => ['ghost'];
        yield 'no role at all' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tokens')]
    public function test_the_list_is_empty_and_a_call_is_an_unknown_tool(string $role): void
    {
        $abilities = ['gate:read', 'gate:ceiling:internal'];
        if ($role !== '') {
            $abilities[] = 'gate:role:'.$role;
        }
        $token = TokenFactory::create($abilities)->plainTextToken;

        $list = $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $this->assertSame([], $list['result']['tools']);

        $unknown = $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'no_such_tool', 'arguments' => []]]);
        $hidden = $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['text' => 'x']]]);

        $this->assertSame($unknown, $hidden);
        $this->assertSame('role.unknown', $this->lastReceipt()['reason']);
    }
}
