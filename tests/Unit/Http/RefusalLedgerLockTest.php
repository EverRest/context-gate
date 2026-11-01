<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Http;

use ContextGate\Http\RefusalLedger;
use ContextGate\Support\GateCache;
use ContextGate\Tests\TestCase;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** The refusal counter is read-modify-write; each bump runs under its key's lock, and a lock timeout only skips the count. */
class RefusalLedgerLockTest extends TestCase
{
    private SpyStore $spy;

    protected function setUp(): void
    {
        parent::setUp();

        $spy = $this->spy = new SpyStore();
        Cache::extend('refusal-spy', fn () => Cache::repository($spy));
        config([
            'cache.stores.refusal-spy' => ['driver' => 'refusal-spy'],
            'context-gate.cache_store' => 'refusal-spy',
            'context-gate.http.refusals_per_minute' => 100,
        ]);
    }

    public function test_the_count_is_exact_after_sequential_bumps_through_the_lock(): void
    {
        $request = $this->request();

        for ($i = 0; $i < 20; $i++) {
            RefusalLedger::record($request);
        }

        $this->assertSame(20, (int) GateCache::store()->get(RefusalLedger::key($request)));
        // one lock per bump: the credential key and the per-IP key, for each of the 20 refusals
        $this->assertCount(40, $this->spy->locked);
        $this->assertStringStartsWith('context-gate:refusals:lock:gate:refusals:', $this->spy->locked[0]);
    }

    public function test_a_lock_timeout_skips_the_count_and_does_not_crash_the_request(): void
    {
        $this->spy->timeout = true;
        $request = $this->request();

        // The refusal itself is still refused by the caller; only the count is skipped, so no throttle trips here.
        RefusalLedger::record($request);

        $this->assertFalse(RefusalLedger::blocked($request));
        $this->assertSame(0, (int) GateCache::store()->get(RefusalLedger::key($request)));
    }

    private function request(): Request
    {
        return Request::create('/', 'POST', server: ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_AUTHORIZATION' => 'Bearer 1|lock']);
    }
}

/** An array store that records which locks were requested, and can make every lock time out. */
final class SpyStore extends ArrayStore
{
    /** @var list<string> */
    public array $locked = [];

    public bool $timeout = false;

    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        $this->locked[] = $name;

        if ($this->timeout) {
            return new TimingOutLock($this, $name, $seconds, $owner);
        }

        return parent::lock($name, $seconds, $owner);
    }
}

/** A lock that never gets the key: waiting for it fails the way a busy key does. */
final class TimingOutLock extends ArrayLock
{
    public function block($seconds, $callback = null)
    {
        throw new LockTimeoutException();
    }
}
