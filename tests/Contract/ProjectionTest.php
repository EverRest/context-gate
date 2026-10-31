<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;
use ContextGate\Server\GateServer;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Transport\FakeTransporter;

/** User Story 1, scenario 1: the response holds exactly the contract fields. */
class ProjectionTest extends TestCase
{
    public function test_tool_response_contains_exactly_the_contract_fields(): void
    {
        Gate::define('echo.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['order_status']]);
        config(['context-gate.classification' => ['orders' => ['status' => 'public', 'total' => 'internal', 'notes' => 'internal']]]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'order_status' => [
                'enabled' => true,
                'class' => OrderStatusTool::class,
                'contract' => OrderStatusProjection::class,
                'gate' => 'echo.allow',
                'fingerprint' => hash('sha256', (new OrderStatusTool())->description()),
            ],
        ]]);

        $raw = $this->send(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'order_status', 'arguments' => ['text' => 'ORD_1']],
        ], JSON_THROW_ON_ERROR))[0];

        $response = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($response['result']['isError']);
        $this->assertSame(
            ['status' => 'paid', 'total' => 10],
            json_decode($response['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR),
        );
    }

    /** @return list<string> */
    private function send(string ...$messages): array
    {
        $transport = new class () extends FakeTransporter {
            /** @var list<string> */
            public array $sent = [];

            public function send(string $message): void
            {
                $this->sent[] = $message;
            }
        };

        $server = new GateServer($transport);
        $server->start();

        foreach ($messages as $message) {
            $server->handle($message);
        }

        return $transport->sent;
    }
}

final class OrderStatusProjection extends ContextContract
{
    public function fields(): array
    {
        return ['status' => Field::plain(), 'total' => Field::plain()];
    }

    public function model(): string
    {
        return ProjectionOrder::class;
    }
}

final class OrderStatusTool extends EchoTool
{
    public function name(): string
    {
        return 'order_status';
    }

    public function handle(array $arguments): Response
    {
        return Response::text(json_encode(['status' => 'paid', 'total' => 10, 'notes' => 'internal remark'], JSON_THROW_ON_ERROR));
    }
}

final class ProjectionOrder extends Model
{
    protected $table = 'orders';
}
