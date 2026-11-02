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
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;

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

    #[Group('finding-refusal-lock-bump-takes-lock')]
    public function test_each_bump_takes_the_credential_key_lock(): void
    {
        $request = $this->request();

        for ($i = 0; $i < 20; $i++) {
            RefusalLedger::record($request);
        }

        $this->assertSame(20, (int) GateCache::store()->get(RefusalLedger::key($request)));
        // one lock per bump of the credential key only: the per-IP counter is never locked
        $this->assertCount(20, $this->spy->locked);
        $this->assertStringStartsWith('context-gate:lock:refusals:cred:', $this->spy->locked[0]);
    }

    public function test_the_ip_counter_is_never_locked(): void
    {
        RefusalLedger::record($this->request());

        foreach ($this->spy->locked as $name) {
            $this->assertStringNotContainsString('refusals:ip:', $name);
        }
    }

    #[Group('finding-refusal-lock-never-delays')]
    public function test_a_held_counter_lock_never_delays_a_refusal(): void
    {
        $request = $this->request();
        $holder = $this->spy->lock(str_replace('context-gate:', 'context-gate:lock:', RefusalLedger::key($request)), 60);
        $this->assertTrue($holder->get());

        $started = microtime(true);
        RefusalLedger::record($request);

        $this->assertLessThan(0.5, microtime(true) - $started, 'the refusal path waited on the lock');
        $this->assertSame(1, (int) GateCache::store()->get(RefusalLedger::key($request)));
    }

    #[Group('finding-refusal-lock-timeout-fallback')]
    public function test_a_lock_timeout_falls_back_to_an_unlocked_count_and_does_not_crash_the_request(): void
    {
        $this->spy->timeout = true;
        $request = $this->request();

        // The refusal itself is still refused by the caller; the count is still kept (unlocked), so the trip is not late.
        RefusalLedger::record($request);

        $this->assertFalse(RefusalLedger::blocked($request));
        $this->assertSame(1, (int) GateCache::store()->get(RefusalLedger::key($request)));
    }

    public function test_a_count_that_jumps_past_the_limit_trips_once(): void
    {
        config(['context-gate.http.refusals_per_minute' => 3]);
        $trips = 0;
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line) use (&$trips): void {
            if (str_contains($line, 'http.throttled')) {
                $trips++;
            }
        });
        $request = $this->request();
        GateCache::store()->put(RefusalLedger::key($request), 5, 60);

        RefusalLedger::record($request);
        RefusalLedger::record($request);

        $this->assertTrue(RefusalLedger::blocked($request));
        $this->assertSame(1, $trips);
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
