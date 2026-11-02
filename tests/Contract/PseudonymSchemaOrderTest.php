<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\RoleFixture;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;
use ContextGate\Contracts\IssuesPseudonyms;
use ContextGate\Tests\Fixtures\CustomerRecord;
use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Server\Fingerprint;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Response;
use ContextGate\Server\Tool;

/** The input schema runs on the pseudonym the client sent, before it is resolved; the handler gets the record id. */
class PseudonymSchemaOrderTest extends TestCase
{
    use GateCallHarness;

    public function test_a_valid_pseudonym_passes_a_pattern_schema_and_the_handler_gets_the_resolved_id(): void
    {
        $this->setUpTool();
        $value = (new Pseudonymizer())->pseudonym('cus', 'customers', '42', 'session:alice');

        $result = $this->callTool('lookup', ['customer_id' => $value]);

        $this->assertFalse($result['isError']);
        $this->assertSame('42', PatternLookupTool::$received);
    }

    public function test_a_pseudonym_that_fails_the_schema_is_invalid_before_any_lookup(): void
    {
        $this->setUpTool();
        $spy = new class () extends Pseudonymizer {
            public int $resolves = 0;

            public function resolve(string $pseudonym, string $scope, ?string $caller = null): ?array
            {
                $this->resolves++;

                return parent::resolve($pseudonym, $scope, $caller);
            }
        };
        app()->instance(IssuesPseudonyms::class, $spy);

        $result = $this->callTool('lookup', ['customer_id' => 'cus_toolong0123456789']);

        $this->assertTrue($result['isError']);
        $this->assertStringStartsWith('Invalid arguments: ', $result['content'][0]['text']);
        $this->assertSame(0, $spy->resolves);
    }

    public function test_an_unknown_pseudonym_that_passes_the_schema_is_pseudonym_unknown(): void
    {
        $this->setUpTool();
        $this->recordLines();

        $result = $this->callTool('lookup', ['customer_id' => 'cus_0123456789']);

        $this->assertSame('Request refused.', $result['content'][0]['text']);
        $this->assertSame('pseudonym.unknown', $this->lastReceipt()['reason']);
    }

    private function setUpTool(): void
    {
        Gate::define('lookup.allow', fn (): bool => true);
        PatternLookupTool::$received = null;
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.classification' => ['customers' => ['customer_id' => 'internal']]]);
        config(RoleFixture::stdio('alice', ['lookup']));
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'lookup' => [
                'enabled' => true,
                'gate' => 'lookup.allow',
                'class' => PatternLookupTool::class,
                'contract' => PatternLookupContract::class,
                'fingerprint' => Fingerprint::of(new PatternLookupTool()),
            ],
        ]]);
    }
}

/** Test-only tool: the schema constrains the pseudonym string the client sends. */
class PatternLookupTool extends Tool
{
    public static ?string $received = null;

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
            'properties' => ['customer_id' => ['type' => 'string', 'pattern' => '^cus_[0-9a-f]{10}$|^cus_0123456789$', 'maxLength' => 14]],
            'required' => ['customer_id'],
        ];
    }

    public function handle(array $arguments): Response
    {
        self::$received = (string) $arguments['customer_id'];

        return Response::text((string) json_encode([['id' => $arguments['customer_id']]], JSON_THROW_ON_ERROR));
    }
}

/** Test-only contract: customer_id is a pseudonym. */
class PatternLookupContract extends ContextContract
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
