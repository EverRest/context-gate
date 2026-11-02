<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Support;

use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Settings\SettingsSchema;
use ContextGate\Support\GateCache;
use ContextGate\Support\StoreUnavailable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use ContextGate\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use ReflectionProperty;

class GateCacheTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.stores.gate', ['driver' => 'array']);
    }

    public function test_null_uses_the_host_default_store(): void
    {
        $this->assertSame(Cache::store(), GateCache::store());
    }

    public function test_a_configured_store_is_used_instead_of_the_default(): void
    {
        config(['context-gate.cache_store' => 'gate']);

        $this->assertSame(Cache::store('gate'), GateCache::store());
        $this->assertNotSame(Cache::store(), GateCache::store());
    }

    public function test_the_pseudonymizer_writes_to_the_configured_store(): void
    {
        config(['context-gate.cache_store' => 'gate']);

        (new Pseudonymizer())->pseudonym('cus', 'customers', '42', 'day:lookup', 'alice');

        $this->assertNotSame([], $this->keys('gate'));
        $this->assertSame([], $this->keys('array'));
    }

    public function test_a_non_string_cache_store_is_a_violation(): void
    {
        $this->assertSame(['cache_store'], array_column(SettingsSchema::violations(['cache_store' => 5]), 'key'));
        $this->assertSame([], SettingsSchema::violations(['cache_store' => null]));
        $this->assertSame([], SettingsSchema::violations(['cache_store' => 'redis']));
    }

    public function test_gate_check_reports_an_invalid_cache_store(): void
    {
        config(['context-gate.cache_store' => 5]);

        $this->assertSame(1, Artisan::call('gate:check'));
        $this->assertStringContainsString('context-gate.cache_store', Artisan::output());
    }

    public function test_name_is_the_configured_string_or_null(): void
    {
        config(['context-gate.cache_store' => 'gate']);
        $this->assertSame('gate', GateCache::name());

        config(['context-gate.cache_store' => null]);
        $this->assertNull(GateCache::name());
    }

    public function test_keys_live_under_the_package_namespace(): void
    {
        $this->assertSame('context-gate:budget:day:x', GateCache::key('budget:day:x'));
        $this->assertSame('context-gate:lock:rate:x', GateCache::lockKey('rate:x'));
    }

    public function test_guard_passes_a_value_through(): void
    {
        $this->assertSame(7, GateCache::guard(fn () => 7));
    }

    public function test_guard_wraps_a_store_failure_without_its_message(): void
    {
        $original = new \RuntimeException('host=10.0.0.1 password=FAKE_FIXTURE_ONLY');
        $caught = $this->thrown(fn () => GateCache::guard(fn () => throw $original));

        $this->assertInstanceOf(StoreUnavailable::class, $caught);
        $this->assertStringNotContainsString('10.0.0.1', $caught->getMessage());
        $this->assertStringNotContainsString('FAKE_FIXTURE_ONLY', $caught->getMessage());
        $this->assertSame($original, $caught->getPrevious());
    }

    public function test_guard_wraps_a_lock_timeout(): void
    {
        $this->expectException(StoreUnavailable::class);

        GateCache::guard(function () {
            throw new LockTimeoutException();
        });
    }

    private function thrown(\Closure $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e;
        }

        $this->fail('Nothing was thrown.');
    }

    /** @return list<string> */
    private function keys(string $store): array
    {
        $backing = Cache::store($store)->getStore();
        $this->assertInstanceOf(ArrayStore::class, $backing);

        /** @var array<string, mixed> $storage */
        $storage = (new ReflectionProperty(ArrayStore::class, 'storage'))->getValue($backing);

        return array_keys($storage);
    }
}
