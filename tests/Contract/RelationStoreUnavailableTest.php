<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;
use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Tests\Fakes\FlakyStore;
use ContextGate\Tests\Fixtures\CustomerRecord;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;
use Illuminate\Cache\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review finding (spec 008): a relation whose related field is a pseudonym that the store cannot hold must refuse the
 * call with store.unavailable. It must not drop the relation field and serve the row without it.
 */
class RelationStoreUnavailableTest extends TestCase
{
    use GateCallHarness;

    protected function setUp(): void
    {
        parent::setUp();

        Pseudonymizer::usePersistentSessions(true, 3600);
    }

    protected function tearDown(): void
    {
        FlakyStore::reset();

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The session pseudonym encrypts its state; the fixture application config has no key or cipher.
        $app['config']->set('app.key', 'base64:RkFLRV9GSVhUVVJFX09OTFlfVEVTVF9LRVlfMzJCWVQ=');
        $app['config']->set('app.cipher', 'AES-256-CBC');
    }

    public function test_a_relation_the_store_cannot_serve_refuses_the_call_and_serves_no_row(): void
    {
        $this->recordLines();
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        DB::table('customers')->insert(['id' => 1, 'name' => 'Ada']);
        $this->configureTool(SessionRelationContract::class, [
            'orders' => ['status' => 'public', 'customer_id' => 'internal'],
            'customers' => ['id' => 'public', 'name' => 'personal'],
        ]);
        ProbeTool::$output = (string) json_encode([['status' => 'paid', 'customer_id' => 1]]);

        // Only the pseudonym state is down; the rest of the gate answers.
        FlakyStore::$down = true;
        FlakyStore::$only = 'pseudonym:';
        Cache::extend('flaky', fn () => new Repository(new FlakyStore()));
        config(['cache.stores.flaky' => ['driver' => 'flaky'], 'context-gate.cache_store' => 'flaky']);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertTrue($result['isError'], 'the call is refused, not served without the relation');
        $this->assertSame('store.unavailable', $this->lastReceipt()['reason']);
        $this->assertStringNotContainsString('paid', json_encode($result, JSON_THROW_ON_ERROR), 'no row is served');
    }
}

/** Test-only: the related customer's name is a session pseudonym, which the relation must read from the store. */
class SessionRelationContract extends ContextContract
{
    public function fields(): array
    {
        return ['status' => Field::plain(), 'customer_id' => Field::relation(SessionCustomerContract::class)];
    }

    public function model(): string
    {
        return \ContextGate\Tests\Fixtures\ProbeOrderModel::class;
    }
}

/** Test-only: the related customer's name is a session-scoped pseudonym. */
class SessionCustomerContract extends ContextContract
{
    public function fields(): array
    {
        return ['name' => Field::pseudonym('USR')];
    }

    public function model(): string
    {
        return CustomerRecord::class;
    }
}
