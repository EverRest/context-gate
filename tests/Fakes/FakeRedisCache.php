<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fakes;

use Closure;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Throwable;

/**
 * A cache store that is a Redis store to gate:check and answers its probe in memory. No socket is opened.
 * Spec 008: the store check classifies by driver name, probes, and reads the eviction policy and the prefix.
 */
final class FakeRedisCache
{
    /** @param (Closure(string, int): void)|null $onPut receives the key and TTL of each write; $policyFails: CONFIG refused */
    public static function install(?Throwable $putFails = null, string $policy = 'noeviction', string $prefix = 'app-', ?Closure $onPut = null, ?Throwable $policyFails = null): void
    {
        config(['context-gate.cache_store' => 'gate']);
        config(['cache.stores.gate' => ['driver' => 'redis', 'connection' => 'default']]);

        $connection = Mockery::mock();
        $command = $connection->shouldReceive('command')->with('config', ['get', 'maxmemory-policy']);
        $policyFails === null ? $command->andReturn(['maxmemory-policy' => $policy]) : $command->andThrow($policyFails);

        $store = Mockery::mock(RedisStore::class);
        $store->shouldReceive('getPrefix')->andReturn($prefix);
        $store->shouldReceive('connection')->andReturn($connection);

        $repository = Mockery::mock(Repository::class);
        $repository->shouldReceive('getStore')->andReturn($store);
        $stored = [];
        $repository->shouldReceive('put')->andReturnUsing(function (string $key, mixed $value, int $ttl) use ($putFails, $onPut, &$stored): bool {
            if ($onPut !== null) {
                $onPut($key, $ttl);
            }
            if ($putFails !== null) {
                throw $putFails;
            }
            $stored[$key] = $value;

            return true;
        });
        // RedisStore stores a number unserialized and reads it back as the string the server returns; a string round-trips.
        $repository->shouldReceive('get')->andReturnUsing(static function (string $key) use (&$stored): mixed {
            $value = $stored[$key] ?? null;

            return is_int($value) || is_float($value) ? (string) $value : $value;
        });
        $repository->shouldReceive('forget')->andReturnTrue();

        Cache::shouldReceive('store')->andReturn($repository);
    }
}
