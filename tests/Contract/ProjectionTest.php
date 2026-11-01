<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Contract\ContextContract;
use ContextGate\Server\Fingerprint;
use ContextGate\Contract\Field;
use ContextGate\Server\GateServer;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Transport\FakeTransporter;

/** User Story 1, scenario 1: the response holds exactly the contract fields. */
class ProjectionTest extends TestCase
{
    /** @var list<string> */
    private array $receipts = [];

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
                'fingerprint' => Fingerprint::of(new OrderStatusTool()),
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

    /** A personal field in the plain form breaks a load rule: the call is refused, nothing is fetched or returned. */
    public function test_a_personal_field_with_a_plain_form_is_refused_and_never_returned_raw(): void
    {
        $raw = $this->callRaw(
            OrderEmailTool::class,
            OrderEmailPlainProjection::class,
            ['orders' => ['status' => 'public', 'email' => 'personal']],
            'internal',
        );

        $this->assertTrue($raw['result']['isError']);
        $this->assertSame('Request refused.', $raw['result']['content'][0]['text']);
        $this->assertStringNotContainsString('a@example.invalid', (string) json_encode($raw));
    }

    /**
     * A pseudonym needs the record identifier. OrderEmailTool returns rows without an id, so the transform cannot
     * issue a pseudonym and the call fails with transform.failed (F3: a missing id is not a key failure). The raw email never leaves.
     */
    public function test_a_personal_field_with_a_pseudonym_form_is_refused_without_a_record_identifier(): void
    {
        $this->recordReceipts();
        $response = $this->callRaw(
            OrderEmailTool::class,
            OrderEmailPseudonymProjection::class,
            ['orders' => ['status' => 'public', 'email' => 'personal']],
            'internal',
        );

        $this->assertTrue($response['result']['isError']);
        $this->assertMatchesRegularExpression('/^Request failed\. Reference: r_[0-9a-f]{8}$/', $response['result']['content'][0]['text']);
        $record = json_decode($this->receipts[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('transform.failed', $record['reason']);
        $this->assertStringNotContainsString('a@example.invalid', (string) json_encode($response));
    }

    public function test_an_array_value_in_a_plain_field_is_never_returned(): void
    {
        $text = $this->callTool(
            OrderItemsTool::class,
            OrderItemsProjection::class,
            ['orders' => ['status' => 'public', 'items' => 'internal']],
            'internal',
        );

        $this->assertSame(['status' => 'paid'], json_decode($text, true, 512, JSON_THROW_ON_ERROR));
    }

    /** A configured secret ceiling is a configuration error now (CeilingConfigTest); the handler still never runs. */
    public function test_a_secret_ceiling_refuses_the_call_and_writes_a_config_invalid_receipt(): void
    {
        $this->recordReceipts();

        $response = $this->callRaw(
            OrderStatusTool::class,
            OrderStatusProjection::class,
            ['orders' => ['status' => 'public', 'total' => 'internal']],
            'secret',
        );

        $this->assertTrue($response['result']['isError']);
        $this->assertSame('Request refused.', $response['result']['content'][0]['text']);

        $this->assertCount(1, $this->receipts);
        $record = json_decode($this->receipts[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('refused', $record['decision']);
        $this->assertSame('config.invalid', $record['reason']);
    }

    /**
     * @param class-string<EchoTool> $toolClass
     * @param class-string<ContextContract> $contract
     * @param array<string, array<string, string>> $classification
     */
    private function callTool(string $toolClass, string $contract, array $classification, string $ceiling): string
    {
        $response = $this->callRaw($toolClass, $contract, $classification, $ceiling);

        $this->assertFalse($response['result']['isError']);

        return $response['result']['content'][0]['text'];
    }

    /**
     * @param class-string<EchoTool> $toolClass
     * @param class-string<ContextContract> $contract
     * @param array<string, array<string, string>> $classification
     * @return array<string, mixed>
     */
    private function callRaw(string $toolClass, string $contract, array $classification, string $ceiling): array
    {
        $name = (new $toolClass())->name();

        Gate::define('echo.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => [$name]]);
        config(['context-gate.classification' => $classification]);
        config(['context-gate.firewall.ceiling' => $ceiling]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            $name => [
                'enabled' => true,
                'class' => $toolClass,
                'contract' => $contract,
                'gate' => 'echo.allow',
                'fingerprint' => Fingerprint::of(new $toolClass()),
            ],
        ]]);

        $raw = $this->send(json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => ['text' => 'ORD_1']],
        ], JSON_THROW_ON_ERROR))[0];

        /** @var array<string, mixed> $response */
        $response = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $response;
    }

    private function recordReceipts(): void
    {
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->receipts[] = $line;
        });
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

final class OrderEmailPlainProjection extends ContextContract
{
    public function fields(): array
    {
        return ['status' => Field::plain(), 'email' => Field::plain()];
    }

    public function model(): string
    {
        return ProjectionOrder::class;
    }
}

final class OrderEmailPseudonymProjection extends ContextContract
{
    public function fields(): array
    {
        return ['status' => Field::plain(), 'email' => Field::pseudonym('cust')];
    }

    public function model(): string
    {
        return ProjectionOrder::class;
    }
}

final class OrderItemsProjection extends ContextContract
{
    public function fields(): array
    {
        return ['status' => Field::plain(), 'items' => Field::plain()];
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

final class OrderEmailTool extends EchoTool
{
    public function name(): string
    {
        return 'order_email';
    }

    public function handle(array $arguments): Response
    {
        return Response::text(json_encode(['status' => 'paid', 'email' => 'a@example.invalid'], JSON_THROW_ON_ERROR));
    }
}

final class OrderItemsTool extends EchoTool
{
    public function name(): string
    {
        return 'order_items';
    }

    public function handle(array $arguments): Response
    {
        return Response::text(json_encode(['status' => 'paid', 'items' => [['sku' => 'A']]], JSON_THROW_ON_ERROR));
    }
}

final class ProjectionOrder extends Model
{
    protected $table = 'orders';
}
