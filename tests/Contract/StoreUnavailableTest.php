<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\RoleFixture;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;
use ContextGate\Server\Fingerprint;
use ContextGate\Server\Tool;
use ContextGate\Tests\Fakes\FlakyStore;
use ContextGate\Tests\Fixtures\CustomerRecord;
use ContextGate\Tests\Fixtures\DayPseudonymCustomerContract;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Response;
use RuntimeException;

/**
 * Spec 008 US4 (T017): a store that fails in a tool call refuses it with store.unavailable; no handler runs or row is
 * served; the next call is served once the store answers again. Keys are matched by fragment, so the test does not
 * depend on the key family names.
 */
class StoreUnavailableTest extends TestCase
{
    use GateCallHarness;

    protected function tearDown(): void
    {
        FlakyStore::reset();

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The day-scoped pseudonym encrypts its state; the fixture application config has no key or cipher.
        $app['config']->set('app.key', 'base64:RkFLRV9GSVhUVVJFX09OTFlfVEVTVF9LRVlfMzJCWVQ=');
        $app['config']->set('app.cipher', 'AES-256-CBC');
    }

    /** The gate store fails for keys containing $only (every key when null), until the test sets it back. */
    private function storeDown(?string $only = null): void
    {
        FlakyStore::$down = true;
        FlakyStore::$only = $only;
        Cache::extend('flaky', fn () => new Repository(new FlakyStore()));
        config(['cache.stores.flaky' => ['driver' => 'flaky'], 'context-gate.cache_store' => 'flaky']);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed> the receipt of the last call, which must be a store failure
     */
    private function assertStoreFailure(array $result, string $reason = 'store.unavailable'): array
    {
        $this->assertTrue($result['isError']);
        $this->assertMatchesRegularExpression('/^Request failed\. Reference: r_[0-9a-f]{8}$/', $result['content'][0]['text']);

        $receipt = $this->lastReceipt();
        $this->assertSame($reason, $receipt['reason']);
        $this->assertSame('failed', $receipt['decision']);

        return $receipt;
    }

    public function test_a_store_that_is_down_refuses_the_call_before_the_handler_runs(): void
    {
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['id' => 'public', 'status' => 'public', 'note' => 'internal', 'email' => 'personal']]);
        $this->storeDown();
        ProbeTool::$output = (string) json_encode([['id' => 5, 'email' => 'x']]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertStoreFailure($result);
        $this->assertFalse(ProbeTool::$called, 'the handler must not run');
        $note = $this->noteLine();
        $this->assertSame(RuntimeException::class, $note['exception']);
        $this->assertStringNotContainsString('10.0.0.1', json_encode($note, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('FAKE_FIXTURE_ONLY', json_encode($note, JSON_THROW_ON_ERROR));
    }

    public function test_a_store_that_fails_after_the_rate_limit_serves_no_row(): void
    {
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['id' => 'public', 'status' => 'public', 'note' => 'internal', 'email' => 'personal']]);
        $this->storeDown('budget');
        ProbeTool::$output = (string) json_encode([['id' => 5, 'email' => 'x']]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertStoreFailure($result);
        $this->assertStringNotContainsString('"email"', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_a_pseudonym_transform_with_the_store_down_is_store_unavailable_not_keyfail(): void
    {
        $this->recordLines();
        $this->configureTool(DayPseudonymCustomerContract::class, ['customers' => ['name' => 'personal']]);
        $this->storeDown('pseudonym:');
        ProbeTool::$output = (string) json_encode([['id' => 5, 'name' => 'Ann']]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertStoreFailure($result);
        $this->assertStringNotContainsString('Ann', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_a_pseudonym_argument_with_the_store_down_is_store_unavailable_not_unknown(): void
    {
        $this->recordLines();
        Gate::define('lookup.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.classification' => ['customers' => ['customer_id' => 'internal']]]);
        config(RoleFixture::stdio('alice', ['lookup']));
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'lookup' => [
                'enabled' => true,
                'gate' => 'lookup.allow',
                'class' => StoreLookupTool::class,
                'contract' => StoreLookupContract::class,
                'fingerprint' => Fingerprint::of(new StoreLookupTool()),
            ],
        ]]);
        $this->storeDown('pseudonym:');

        $result = $this->callTool('lookup', ['customer_id' => 'cus_0123456789']);

        $this->assertStoreFailure($result);
    }

    public function test_a_store_name_the_host_does_not_define_refuses_every_call(): void
    {
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['id' => 'public', 'status' => 'public', 'note' => 'internal', 'email' => 'personal']]);
        config(['context-gate.cache_store' => 'undefined-store']);
        ProbeTool::$output = (string) json_encode([['id' => 5]]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertStoreFailure($result);
        $this->assertFalse(ProbeTool::$called, 'the handler must not run');
    }

    public function test_the_next_call_is_served_once_the_store_is_back(): void
    {
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['id' => 'public', 'status' => 'public', 'note' => 'internal', 'email' => 'personal']]);
        $this->storeDown();
        ProbeTool::$output = (string) json_encode([['id' => 5]]);
        $this->callTool('probe', ['text' => 'x']);

        FlakyStore::$down = false;
        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertFalse($result['isError'] ?? false);
        $this->assertTrue(ProbeTool::$called);
    }

    /** @return array<string, mixed> the note the failure wrote: the reference, the class and the place, no decision */
    private function noteLine(): array
    {
        $notes = array_values(array_filter(
            array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $this->lines),
            static fn (array $record): bool => array_key_exists('exception', $record),
        ));

        return $notes[count($notes) - 1];
    }
}

class StoreLookupTool extends Tool
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
        return Response::text((string) json_encode(['id' => $arguments['customer_id']], JSON_THROW_ON_ERROR));
    }
}

/** Test-only contract: customer_id is a day-scoped pseudonym, so the argument is resolved from the store. */
class StoreLookupContract extends ContextContract
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
