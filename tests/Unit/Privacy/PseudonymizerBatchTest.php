<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Privacy;

use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class PseudonymizerBatchTest extends TestCase
{
    protected function tearDown(): void
    {
        Pseudonymizer::usePersistentSessions(false);
        Pseudonymizer::flushSessions();
        parent::tearDown();
    }

    public function test_2000_ids_take_one_lock_and_one_save_and_match_the_single_id_path(): void
    {
        $store = $this->spyStore();
        $ids = array_map('strval', range(1, 2000));

        $batch = array_values((new Pseudonymizer())->pseudonyms('cus', 'customers', $ids, 'day:tool', 'alice'));

        $this->assertSame(1, $store->lockCalls);
        $this->assertSame(1, $store->puts);
        $this->assertCount(2000, $batch);

        foreach ([1, 777, 2000] as $id) {
            $this->assertSame($batch[$id - 1], (new Pseudonymizer())->pseudonym('cus', 'customers', (string) $id, 'day:tool', 'alice'));
            $this->assertSame(['table' => 'customers', 'id' => (string) $id], (new Pseudonymizer())->resolve($batch[$id - 1], 'day:tool', 'alice'));
        }
    }

    public function test_a_put_that_the_store_refuses_fails_closed(): void
    {
        $store = $this->spyStore();
        $store->refuse = true;

        $this->expectException(RuntimeException::class);

        (new Pseudonymizer())->pseudonym('cus', 'customers', '1', 'day:tool', 'alice');
    }

    public function test_an_unreadable_state_is_replaced_and_resolves_as_unknown(): void
    {
        foreach (['garbage', Crypt::encryptString('not json'), Crypt::encryptString('"a string"')] as $bad) {
            Cache::forget('context-gate:pseudonym:day:alice:tool');
            Cache::put('context-gate:pseudonym:day:alice:tool', $bad, 600);

            $this->assertNull((new Pseudonymizer())->resolve('cus_aaaaaaaaaa', 'day:tool', 'alice'));

            $value = (new Pseudonymizer())->pseudonym('cus', 'customers', '1', 'day:tool', 'alice');

            $this->assertSame(['table' => 'customers', 'id' => '1'], (new Pseudonymizer())->resolve($value, 'day:tool', 'alice'));
        }
    }

    public function test_a_session_state_in_steady_use_dies_seven_days_after_creation(): void
    {
        Pseudonymizer::usePersistentSessions(true, 3 * 86400);
        $scope = 'session:'.uniqid('', true);
        $value = (new Pseudonymizer())->pseudonym('cus', 'customers', '1', $scope);

        foreach ([1, 2] as $_) {
            $this->travel(2 * 86400 + 80000)->seconds();
            (new Pseudonymizer())->pseudonym('cus', 'customers', '1', $scope); // slides the expiry
        }

        $this->assertNotNull((new Pseudonymizer())->resolve($value, $scope));

        $this->travel(2 * 86400)->seconds(); // now past created + 7 days

        $this->assertNull((new Pseudonymizer())->resolve($value, $scope));
    }

    public function test_a_scope_over_the_map_cap_rotates_its_key(): void
    {
        $pseudonymizer = new Pseudonymizer();
        $first = array_values($pseudonymizer->pseudonyms('cus', 'customers', array_map('strval', range(1, 6000)), 'day:tool', 'alice'));
        $second = array_values($pseudonymizer->pseudonyms('cus', 'customers', array_map('strval', range(6001, 12000)), 'day:tool', 'alice'));

        $this->assertNull($pseudonymizer->resolve($first[0], 'day:tool', 'alice'), 'old pseudonyms stop resolving');
        $this->assertNotNull($pseudonymizer->resolve($second[0], 'day:tool', 'alice'));
    }

    public function test_a_memory_session_has_the_same_cap(): void
    {
        $pseudonymizer = new Pseudonymizer();
        $first = array_values($pseudonymizer->pseudonyms('cus', 'customers', array_map('strval', range(1, 6000)), 'session:cap'));
        $pseudonymizer->pseudonyms('cus', 'customers', array_map('strval', range(6001, 12000)), 'session:cap');

        $this->assertNull($pseudonymizer->resolve($first[0], 'session:cap'));
    }

    private function spyStore(): SpyArrayStore
    {
        $store = new SpyArrayStore();
        Cache::extend('spy', fn () => Cache::repository($store));
        config(['cache.stores.spy' => ['driver' => 'spy'], 'cache.default' => 'spy']);
        Cache::forgetDriver('spy');
        Cache::forgetDriver('array');

        return $store;
    }
}

final class SpyArrayStore extends ArrayStore
{
    public int $lockCalls = 0;

    public int $puts = 0;

    public bool $refuse = false;

    public function lock($name, $seconds = 0, $owner = null)
    {
        $this->lockCalls++;

        return parent::lock($name, $seconds, $owner);
    }

    public function put($key, $value, $seconds)
    {
        $this->puts++;

        return $this->refuse ? false : parent::put($key, $value, $seconds);
    }
}
