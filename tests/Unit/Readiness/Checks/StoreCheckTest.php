<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness\Checks;

use ContextGate\Classification\Catalog;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\Checks\StoreCheck;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Tests\Fakes\FakeRedisCache;
use ContextGate\Tests\TestCase;

/** Spec 008 US1, FR-002, FR-012, FR-013: the gate store is classified by driver, probed, and checked for eviction. */
class StoreCheckTest extends TestCase
{
    /** @var list<array{0: string, 1: int}> the key and TTL of each probe write */
    private array $puts = [];

    private function redis(?\Throwable $putFails = null, string $policy = 'noeviction', string $prefix = 'app-', ?\Throwable $policyFails = null): void
    {
        FakeRedisCache::install($putFails, $policy, $prefix, function (string $key, int $ttl): void {
            $this->puts[] = [$key, $ttl];
        }, $policyFails);
    }

    private function context(): ReadinessContext
    {
        return new ReadinessContext([], new Catalog([]), []);
    }

    private function environment(string $name): void
    {
        app()->detectEnvironment(static fn (): string => $name);
    }

    private function useStore(string $driver): void
    {
        config(['context-gate.cache_store' => 'gate']);
        config(['cache.stores.gate' => ['driver' => $driver]]);
    }

    public function test_array_and_null_drivers_fail_in_every_environment(): void
    {
        foreach (['local', 'production'] as $env) {
            $this->environment($env);
            foreach (['array', 'null'] as $driver) {
                $this->useStore($driver);

                $result = (new StoreCheck())->run($this->context());

                $this->assertSame(CheckStatus::Fail, $result->status, "$driver in $env");
                $this->assertSame('context-gate.cache_store', $result->subject);
                $this->assertStringContainsString('reset in each process', $result->message);
                $this->assertStringContainsString('[gate]', $result->message);
                $this->assertStringContainsString("driver $driver", $result->message);
                $this->assertStringContainsString('Redis', $result->fix);
            }
        }
    }

    public function test_file_fails_in_production_and_warns_elsewhere(): void
    {
        $this->useStore('file');

        $this->environment('production');
        $this->assertSame(CheckStatus::Fail, (new StoreCheck())->run($this->context())->status);

        $this->environment('local');
        $result = (new StoreCheck())->run($this->context());
        $this->assertSame(CheckStatus::Warn, $result->status);
        $this->assertStringContainsString('one host', $result->message);
    }

    public function test_database_warns_with_the_row_lock_cost(): void
    {
        $this->environment('production');
        $this->useStore('database');

        $result = (new StoreCheck())->run($this->context());

        $this->assertSame(CheckStatus::Warn, $result->status);
        $this->assertStringContainsString('row locks', $result->message);
        $this->assertStringContainsString('[gate]', $result->message);
    }

    public function test_a_redis_store_that_answers_the_probe_passes(): void
    {
        $this->environment('production');
        $this->redis();

        $result = (new StoreCheck())->run($this->context());

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertStringContainsString('answered the probe', $result->message);
        $this->assertStringContainsString('noeviction', $result->message);
    }

    public function test_a_probe_that_throws_fails_with_the_class_only(): void
    {
        $this->environment('production');
        $this->redis(new \RuntimeException('connect tcp://10.0.0.1:6379 password=FAKE_FIXTURE_ONLY'));

        $result = (new StoreCheck())->run($this->context());

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertStringContainsString('RuntimeException', $result->message);
        $this->assertStringNotContainsString('10.0.0.1', $result->message);
        $this->assertStringNotContainsString('FAKE_FIXTURE_ONLY', $result->message);
    }

    public function test_an_unknown_driver_warns_naming_it(): void
    {
        $this->environment('production');
        $this->useStore('dynamodb');

        $result = (new StoreCheck())->run($this->context());

        $this->assertSame(CheckStatus::Warn, $result->status);
        $this->assertStringContainsString('dynamodb', $result->message);
    }

    public function test_a_store_name_missing_from_the_cache_config_fails(): void
    {
        config(['context-gate.cache_store' => 'nope']);

        $result = (new StoreCheck())->run($this->context());

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('context-gate.cache_store', $result->subject);
        $this->assertStringContainsString('[nope]', $result->message);
    }

    public function test_a_null_store_names_the_host_default(): void
    {
        config(['context-gate.cache_store' => null, 'cache.default' => 'array']);

        $result = (new StoreCheck())->run($this->context());

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertStringContainsString('(host default)', $result->message);
        $this->assertStringContainsString('driver array', $result->message);
    }

    public function test_an_evicting_policy_warns_in_production_only(): void
    {
        $this->redis(policy: 'allkeys-lru');

        $this->environment('production');
        $result = (new StoreCheck())->run($this->context());
        $this->assertSame(CheckStatus::Warn, $result->status);
        $this->assertStringContainsString('allkeys-lru', $result->message);

        $this->environment('local');
        $result = (new StoreCheck())->run($this->context());
        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertStringContainsString('allkeys-lru', $result->message);
    }

    public function test_an_unreadable_policy_warns(): void
    {
        $this->environment('production');
        $this->redis(policyFails: new \RuntimeException('ERR unknown command config'));

        $result = (new StoreCheck())->run($this->context());

        $this->assertSame(CheckStatus::Warn, $result->status);
        $this->assertStringContainsString('could not be read', $result->message);
    }

    public function test_an_empty_prefix_warns(): void
    {
        $this->environment('production');
        $this->redis(prefix: '');

        $result = (new StoreCheck())->run($this->context());

        $this->assertSame(CheckStatus::Warn, $result->status);
        $this->assertStringContainsString('collide', $result->message);
    }

    public function test_the_probe_key_is_under_the_namespace_with_a_five_second_ttl(): void
    {
        $this->environment('production');
        $this->redis();

        (new StoreCheck())->run($this->context());

        $this->assertCount(1, $this->puts);
        $this->assertMatchesRegularExpression('/^context-gate:probe:[0-9a-f]{16}$/', $this->puts[0][0]);
        $this->assertSame(5, $this->puts[0][1]);
    }
}
