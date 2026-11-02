<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Roles;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;
use ContextGate\Server\Fingerprint;
use ContextGate\Tests\Fixtures\CustomerRecord;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Response;

/** spec 010 US2 scenarios 1-2: over HTTP the response is filtered by the narrower of the token's and the role's ceiling. */
class RoleCeilingFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        Gate::define('echo.allow', fn (): bool => true);
        config(['context-gate.classification' => ['customers' => ['status' => 'public', 'total' => 'internal']]]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'order_total' => [
                'enabled' => true,
                'class' => RoleOrderTotalTool::class,
                'contract' => RoleOrderTotalContract::class,
                'gate' => 'echo.allow',
                'fingerprint' => Fingerprint::of(new RoleOrderTotalTool()),
            ],
        ]]);
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info');
    }

    /** @return iterable<string, array{string, string, array<int, array<string, mixed>>}> token ceiling, role ceiling, rows */
    public static function cases(): iterable
    {
        yield 'internal token, public role: only public fields' => ['internal', 'public', [['status' => 'paid']]];
        yield 'personal token, internal role: the role narrows' => ['personal', 'internal', [['status' => 'paid', 'total' => 10]]];
        yield 'personal token, public role: only public fields' => ['personal', 'public', [['status' => 'paid']]];
    }

    /** @param array<int, array<string, mixed>> $rows */
    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function test_the_response_is_filtered_by_the_narrower_ceiling(string $tokenCeiling, string $roleCeiling, array $rows): void
    {
        $token = TokenFactory::create(['gate:read', 'gate:ceiling:'.$tokenCeiling, 'gate:role:support']);
        config(['context-gate.roles.support' => ['tools' => ['order_total'], 'ceiling' => $roleCeiling]]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ])->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => ['name' => 'order_total', 'arguments' => ['text' => 'ORD_1']],
        ]);

        $response->assertOk();
        $content = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['result']['content'][0]['text'];

        $this->assertSame($rows, json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR));
    }
}

final class RoleOrderTotalTool extends EchoTool
{
    public function name(): string
    {
        return 'order_total';
    }

    public function handle(array $arguments): Response
    {
        return Response::text(json_encode([['status' => 'paid', 'total' => 10]], JSON_THROW_ON_ERROR));
    }
}

final class RoleOrderTotalContract extends ContextContract
{
    public function fields(): array
    {
        return ['status' => Field::plain(), 'total' => Field::plain()];
    }

    public function model(): string
    {
        return CustomerRecord::class;
    }
}
