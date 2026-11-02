<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Privacy;

use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Support\StoreUnavailable;
use ContextGate\Tests\TestCase;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Spec 008 US4 (T015): pseudonym state that the store cannot read or write refuses with StoreUnavailable. */
class PseudonymStoreGuardTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The day scope encrypts its state; the fixture application config has no key or cipher (phpunit.xml's fake key).
        $app['config']->set('app.key', 'base64:RkFLRV9GSVhUVVJFX09OTFlfVEVTVF9LRVlfMzJCWVQ=');
        $app['config']->set('app.cipher', 'AES-256-CBC');
    }

    /** The gate reads the store through its name, so the failing store is registered under one. */
    private function useStore(ArrayStore $store): void
    {
        Cache::extend('failing-store', fn () => new Repository($store));
        config(['cache.stores.gate' => ['driver' => 'failing-store'], 'context-gate.cache_store' => 'gate']);
    }

    public function test_a_read_failure_refuses_with_store_unavailable(): void
    {
        $this->useStore(new class () extends ArrayStore {
            public function get($key)
            {
                throw new RuntimeException('connect tcp://10.0.0.1:6379 password=FAKE_FIXTURE_ONLY');
            }
        });

        $this->expectException(StoreUnavailable::class);

        (new Pseudonymizer())->resolve('cus_abc', 'day:lookup', 'alice');
    }

    public function test_a_write_failure_refuses_with_store_unavailable(): void
    {
        $this->useStore(new class () extends ArrayStore {
            public function put($key, $value, $seconds)
            {
                throw new RuntimeException('connect tcp://10.0.0.1:6379 password=FAKE_FIXTURE_ONLY');
            }
        });

        $this->expectException(StoreUnavailable::class);

        (new Pseudonymizer())->pseudonym('cus', 'customers', '42', 'day:lookup', 'alice');
    }

    public function test_a_lock_that_times_out_refuses_with_store_unavailable(): void
    {
        $this->useStore(new class () extends ArrayStore {
            public function lock($name, $seconds = 0, $owner = null)
            {
                return new class ($this, $name, $seconds, $owner) extends ArrayLock {
                    public function block($seconds, $callback = null)
                    {
                        throw new \Illuminate\Contracts\Cache\LockTimeoutException();
                    }
                };
            }
        });

        $this->expectException(StoreUnavailable::class);

        (new Pseudonymizer())->pseudonym('cus', 'customers', '42', 'day:lookup', 'alice');
    }

    public function test_a_value_the_store_refuses_by_size_stays_a_keyfail_refusal_not_a_store_failure(): void
    {
        $this->useStore(new class () extends ArrayStore {
            public function put($key, $value, $seconds)
            {
                return false;
            }
        });

        try {
            (new Pseudonymizer())->pseudonym('cus', 'customers', '42', 'day:lookup', 'alice');
            $this->fail('the size refusal must throw');
        } catch (StoreUnavailable $e) {
            $this->fail('a size refusal is not a store failure');
        } catch (RuntimeException $e) {
            $this->assertSame('The pseudonym state could not be stored.', $e->getMessage());
        }
    }
}
