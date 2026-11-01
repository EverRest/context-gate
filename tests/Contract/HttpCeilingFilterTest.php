<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Contract\ContextContract;
use ContextGate\Server\Fingerprint;
use ContextGate\Contract\Field;
use ContextGate\Tests\Fixtures\CustomerRecord;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Response;

/** FR-012: the ceiling of the token filters an HTTP response, even when the configured ceiling is higher. */
class HttpCeilingFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
    }

    public function test_a_public_token_does_not_receive_an_internal_field_over_http(): void
    {
        Gate::define('echo.allow', fn (): bool => true);
        // The configured (stdio) ceiling is internal, so only the token's public ceiling can hide the total.
        config(['context-gate.firewall.ceiling' => 'internal']);
        config(['context-gate.classification' => ['customers' => ['status' => 'public', 'total' => 'internal']]]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'order_total' => [
                'enabled' => true,
                'class' => OrderTotalHttpTool::class,
                'contract' => OrderTotalHttpContract::class,
                'gate' => 'echo.allow',
                'fingerprint' => Fingerprint::of(new OrderTotalHttpTool()),
            ],
        ]]);

        $token = TokenFactory::create(['gate:read', 'gate:ceiling:public']);
        config(['context-gate.callers.token:'.$token->accessToken->getKey().'.tools' => ['order_total']]);
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info');

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
        $rows = json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([['status' => 'paid']], $rows);
        // The token's ceiling is bound for the request, not written over the configured (stdio) one.
        $this->assertSame('internal', config('context-gate.firewall.ceiling'));
    }
}

final class OrderTotalHttpTool extends EchoTool
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

final class OrderTotalHttpContract extends ContextContract
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
