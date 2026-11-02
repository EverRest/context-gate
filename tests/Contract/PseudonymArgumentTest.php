<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\RoleFixture;
use ContextGate\Contract\ContextContract;
use ContextGate\Server\Fingerprint;
use ContextGate\Contract\Field;
use ContextGate\Privacy\Pseudonymizer;
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
        config(['context-gate.classification' => ['customers' => ['customer_id' => 'internal']]]);
        config(RoleFixture::stdio('alice', ['lookup']));
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'lookup' => [
                'enabled' => true,
                'gate' => 'lookup.allow',
                'class' => PseudonymLookupTool::class,
                'contract' => PseudonymLookupContract::class,
                'fingerprint' => Fingerprint::of(new PseudonymLookupTool()),
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

    public function test_non_string_pseudonym_argument_is_an_input_error(): void
    {
        Gate::define('lookup.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.classification' => ['customers' => ['customer_id' => 'internal']]]);
        config(RoleFixture::stdio('alice', ['lookup']));
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'lookup' => [
                'enabled' => true,
                'gate' => 'lookup.allow',
                'class' => PseudonymLookupTool::class,
                'contract' => PseudonymLookupContract::class,
                'fingerprint' => Fingerprint::of(new PseudonymLookupTool()),
            ],
        ]]);
        $this->recordReceipts();

        /** @var list<mixed> $values */
        $values = [123, ['cus_0123456789'], null];

        foreach ($values as $index => $value) {
            $response = json_decode($this->send(json_encode([
                'jsonrpc' => '2.0',
                'id' => 10,
                'method' => 'tools/call',
                'params' => ['name' => 'lookup', 'arguments' => ['customer_id' => $value]],
            ], JSON_THROW_ON_ERROR))[0], true, 512, JSON_THROW_ON_ERROR);

            $this->assertTrue($response['result']['isError'], 'Refused for '.get_debug_type($value));
            // The schema runs first on what the client sent: a non-string is an input error, never a lookup.
            $this->assertStringStartsWith('Invalid arguments: ', $response['result']['content'][0]['text']);

            $this->assertCount($index + 1, $this->receipts);
            $record = json_decode($this->receipts[$index], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('input.invalid', $record['reason']);
        }
    }

    public function test_pseudonym_from_another_table_or_prefix_is_refused(): void
    {
        Gate::define('lookup.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.classification' => ['customers' => ['customer_id' => 'internal']]]);
        config(RoleFixture::stdio('alice', ['lookup']));
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'lookup' => [
                'enabled' => true,
                'gate' => 'lookup.allow',
                'class' => PseudonymLookupTool::class,
                'contract' => PseudonymLookupContract::class,
                'fingerprint' => Fingerprint::of(new PseudonymLookupTool()),
            ],
        ]]);
        $this->recordReceipts();

        // Both are genuinely issued in the caller's session scope, so only the table or prefix check can refuse them.
        $otherTable = (new Pseudonymizer())->pseudonym('cus', 'orders', '42', 'session:alice');
        $otherPrefix = (new Pseudonymizer())->pseudonym('ord', 'customers', '42', 'session:alice');

        foreach ([$otherTable, $otherPrefix] as $index => $value) {
            $response = json_decode($this->send(json_encode([
                'jsonrpc' => '2.0',
                'id' => 11,
                'method' => 'tools/call',
                'params' => ['name' => 'lookup', 'arguments' => ['customer_id' => $value]],
            ], JSON_THROW_ON_ERROR))[0], true, 512, JSON_THROW_ON_ERROR);

            $this->assertTrue($response['result']['isError'], "Refused for {$value}");
            $this->assertSame('Request refused.', $response['result']['content'][0]['text']);

            $this->assertCount($index + 1, $this->receipts);
            $record = json_decode($this->receipts[$index], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('pseudonym.unknown', $record['reason']);
        }
    }

    public function test_day_scope_pseudonym_resolves_for_its_caller_and_is_refused_for_another(): void
    {
        config(['cache.default' => 'array']);
        Gate::define('lookup.allow', fn (): bool => true);
        config(['context-gate.classification' => ['customers' => ['customer_id' => 'internal']]]);
        config(array_merge(RoleFixture::stdio('alice', ['lookup']), RoleFixture::stdio('bob', ['lookup'])));
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'lookup' => [
                'enabled' => true,
                'gate' => 'lookup.allow',
                'class' => PseudonymLookupTool::class,
                'contract' => DayPseudonymLookupContract::class,
                'fingerprint' => Fingerprint::of(new PseudonymLookupTool()),
            ],
        ]]);
        $this->recordReceipts();

        // Issued to alice in the day scope of this tool, the way the gate stores it.
        $value = (new Pseudonymizer())->pseudonym('cus', 'customers', '42', 'day:lookup', 'alice');

        app()->instance('context-gate.caller', 'alice');
        $own = json_decode($this->send(json_encode([
            'jsonrpc' => '2.0',
            'id' => 12,
            'method' => 'tools/call',
            'params' => ['name' => 'lookup', 'arguments' => ['customer_id' => $value]],
        ], JSON_THROW_ON_ERROR))[0], true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($own['result']['isError'], 'Issuing caller must resolve its own day pseudonym');

        app()->instance('context-gate.caller', 'bob');
        $other = json_decode($this->send(json_encode([
            'jsonrpc' => '2.0',
            'id' => 13,
            'method' => 'tools/call',
            'params' => ['name' => 'lookup', 'arguments' => ['customer_id' => $value]],
        ], JSON_THROW_ON_ERROR))[0], true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($other['result']['isError']);
        $this->assertSame('Request refused.', $other['result']['content'][0]['text']);
        $this->assertCount(2, $this->receipts);
        $record = json_decode($this->receipts[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pseudonym.unknown', $record['reason']);
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
        return Response::text((string) json_encode(['id' => $arguments['customer_id'], 'customer' => $arguments['customer_id']], JSON_THROW_ON_ERROR));
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

/** Test-only contract: customer_id is a day-scoped pseudonym, bound to the caller. */
class DayPseudonymLookupContract extends ContextContract
{
    public function fields(): array
    {
        return ['customer_id' => Field::pseudonym('cus', 'day')];
    }

    public function model(): string
    {
        return CustomerRecord::class;
    }
}
