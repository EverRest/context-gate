<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Contract\ContextContract;
use ContextGate\Server\Fingerprint;
use ContextGate\Contract\Field;
use ContextGate\Tests\Fixtures\CustomerRecord;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Response;
use ContextGate\Tests\Fixtures\EchoTool;

/** User Story 1, scenario 3: a call over HTTP names the token holder in its receipt, never a value. */
class HttpReceiptHolderTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
    }

    public function test_http_tool_call_receipt_names_the_token_and_holds_no_value(): void
    {
        Gate::define('echo.allow', fn (): bool => true);
        config(['context-gate.classification' => ['customers' => ['status' => 'public']]]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'order_status' => [
                'enabled' => true,
                'class' => OrderStatusHttpTool::class,
                'contract' => OrderStatusHttpContract::class,
                'gate' => 'echo.allow',
                'fingerprint' => Fingerprint::of(new OrderStatusHttpTool()),
            ],
        ]]);

        $token = TokenFactory::create(['gate:read', 'gate:ceiling:internal'], null, 'Ana Holder');
        config(['context-gate.callers.token:'.$token->accessToken->getKey().'.tools' => ['order_status']]);
        $this->recordLines();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ])->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'order_status', 'arguments' => ['text' => 'ORD_1']],
        ]);

        $response->assertOk();
        $this->assertCount(1, $this->lines);

        $receipt = json_decode($this->lines[0], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('token:'.$token->accessToken->getKey(), $receipt['caller']);
        $this->assertSame('order_status', $receipt['tool']);
        $this->assertSame('allow', $receipt['decision']);
        $this->assertStringNotContainsString('paid', $this->lines[0]);
        $this->assertStringNotContainsString($token->plainTextToken, $this->lines[0]);
    }

    private function recordLines(): void
    {
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
    }
}

final class OrderStatusHttpTool extends EchoTool
{
    public function name(): string
    {
        return 'order_status';
    }

    public function handle(array $arguments): Response
    {
        return Response::text(json_encode([['status' => 'paid']], JSON_THROW_ON_ERROR));
    }
}

final class OrderStatusHttpContract extends ContextContract
{
    public function fields(): array
    {
        return ['status' => Field::plain()];
    }

    public function model(): string
    {
        return CustomerRecord::class;
    }
}
