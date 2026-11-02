<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Privacy;

use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Tests\Fakes\RecordingStore;
use ContextGate\Tests\TestCase;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/** Spec 008 US5 (T024): a pseudonym scope's state and its lock are under the package namespace. */
class PseudonymizerKeysTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The day scope encrypts its state; the fixture application config has no key or cipher.
        $app['config']->set('app.key', 'base64:RkFLRV9GSVhUVVJFX09OTFlfVEVTVF9LRVlfMzJCWVQ=');
        $app['config']->set('app.cipher', 'AES-256-CBC');
    }

    public function test_the_state_and_its_lock_are_under_the_namespace(): void
    {
        $store = new RecordingStore();
        Cache::extend('recording', fn () => new Repository($store));
        config(['cache.stores.recording' => ['driver' => 'recording'], 'context-gate.cache_store' => 'recording']);

        (new Pseudonymizer())->pseudonym('cus', 'customers', '42', 'day:lookup', 'alice');

        $touched = array_values(array_unique($store->touched));
        $this->assertContains('context-gate:pseudonym:day:alice:lookup', $touched);
        $this->assertContains('context-gate:lock:pseudonym:day:alice:lookup', $touched);
        $this->assertSame([], preg_grep('/^(gate|budget):/', $touched));
    }
}
