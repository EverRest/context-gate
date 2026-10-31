<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;
use ContextGate\Server\GateServer;
use ContextGate\Server\Tool;
use ContextGate\Tests\Fixtures\CustomerRecord;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Transport\FakeTransporter;

class PseudonymArgumentTest extends TestCase
{
    /** @var list<string> */
    private array $receipts = [];

    public function test_unknown_pseudonym_argument_is_refused_with_a_receipt_that_names_no_record(): void
    {
        Gate::define('lookup.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['lookup']]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'lookup' => [
                'enabled' => true,
                'gate' => 'lookup.allow',
                'class' => PseudonymLookupTool::class,
                'contract' => PseudonymLookupContract::class,
                'fingerprint' => hash('sha256', (new PseudonymLookupTool())->description()),
            ],
        ]]);
        $this->recordReceipts();

        $response = json_decode($this->send(json_encode([
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'tools/call',
            'params' => ['name' => 'lookup', 'arguments' => ['customer_id' => 'cus_0123456789']],
        ], JSON_THROW_ON_ERROR))[0], true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($response['result']['isError']);
        $this->assertSame('Request refused.', $response['result']['content'][0]['text']);

        $this->assertCount(1, $this->receipts);
        $record = json_decode($this->receipts[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pseudonym.unknown', $record['reason']);
        $this->assertStringNotContainsString('cus_0123456789', $this->receipts[0]);
        $this->assertStringNotContainsString('customers', $this->receipts[0]);
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

/** Test-only tool that takes a pseudonym argument for a customer. */
class PseudonymLookupTool extends Tool
{
    public function name(): string
    {
        return 'lookup';
    }

    public function description(): string
    {
        return 'Looks up a customer by pseudonym.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['customer_id' => ['type' => 'string']],
            'required' => ['customer_id'],
        ];
    }

    public function handle(array $arguments): Response
    {
        return Response::text((string) json_encode(['customer' => $arguments['customer_id']], JSON_THROW_ON_ERROR));
    }
}

/** Test-only contract: customer_id is a pseudonym, so an argument with that name is resolved on the server. */
class PseudonymLookupContract extends ContextContract
{
    public function fields(): array
    {
        return ['customer_id' => Field::pseudonym('cus')];
    }

    public function model(): string
    {
        return CustomerRecord::class;
    }
}
