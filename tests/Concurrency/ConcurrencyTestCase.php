<?php

declare(strict_types=1);

namespace ContextGate\Tests\Concurrency;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Multi-process tests. They run only with CONTEXT_GATE_CONCURRENCY=1 and spawn PHP worker processes that share one
 * cache store. CONTEXT_GATE_STORE=redis (spec 008) uses a local Redis (REDIS_HOST, REDIS_PORT, database 15) under a key
 * prefix of this run only; the default is a file store in a temporary directory. Each worker prints one JSON line.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    /** Seconds the workers wait after spawn, so that they start together (a crude barrier). */
    private const START_DELAY = 1.5;

    protected string $base = '';

    protected string $store = '';

    /** The Redis key prefix of this run; empty for the file store. */
    protected string $prefix = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('CONTEXT_GATE_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Set CONTEXT_GATE_CONCURRENCY=1 to run the multi-process tests.');
        }

        if ($this->redis() && ! extension_loaded('redis')) {
            $this->markTestSkipped('CONTEXT_GATE_STORE=redis needs the phpredis extension.');
        }

        $this->base = sys_get_temp_dir().'/cg-conc-'.bin2hex(random_bytes(6));
        $this->store = $this->base.'/store';
        mkdir($this->store, 0777, true);

        if ($this->redis()) {
            $this->prefix = 'cg-'.bin2hex(random_bytes(6)).':';
            putenv('CG_RUN_PREFIX='.$this->prefix);
        }
    }

    protected function tearDown(): void
    {
        if ($this->prefix !== '') {
            $this->deleteRedisKeys();
        }

        if ($this->base !== '' && is_dir($this->base)) {
            self::removeTree($this->base);
        }

        parent::tearDown();
    }

    protected function redis(): bool
    {
        return getenv('CONTEXT_GATE_STORE') === 'redis';
    }

    /** CONTEXT_GATE_REPEAT: how many times the budget and rate tests run (SC-001 asks for 100 in the nightly job). */
    protected function repeats(): int
    {
        return max(1, (int) (getenv('CONTEXT_GATE_REPEAT') ?: 1));
    }

    /**
     * Every key of this run in Redis, found with SCAN under the run's prefix (never KEYS: it blocks the server).
     *
     * @return list<string>
     */
    protected function redisKeys(): array
    {
        $redis = $this->redisClient();
        $found = [];
        $iterator = null;

        do {
            $batch = $redis->scan($iterator, $this->prefix.'*', 500);

            if (is_array($batch)) {
                array_push($found, ...$batch);
            }
        } while ($iterator !== 0);

        return $found;
    }

    private function deleteRedisKeys(): void
    {
        $keys = $this->redisKeys();

        if ($keys !== []) {
            $this->redisClient()->del($keys);
        }
    }

    private function redisClient(): \Redis
    {
        $redis = new \Redis();
        $redis->connect(getenv('REDIS_HOST') ?: '127.0.0.1', (int) (getenv('REDIS_PORT') ?: 6379));
        $redis->select(15);

        return $redis;
    }

    /**
     * Runs one worker per job, all against the shared store, and returns each worker's decoded JSON line.
     *
     * @param list<list<string>> $jobs worker arguments after the store path and start time
     * @return list<array<string, mixed>>
     */
    protected function runWorkers(array $jobs): array
    {
        $startAt = microtime(true) + self::START_DELAY;
        $procs = [];

        foreach ($jobs as $i => $args) {
            $errors = $this->base.'/worker-'.$i.'.err';
            $command = [PHP_BINARY, __DIR__.'/Workers/worker.php', $this->store, (string) $startAt, ...$args];
            $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']], $pipes);

            if (! is_resource($proc)) {
                throw new RuntimeException('Could not start worker '.$i);
            }

            $procs[] = [$proc, $pipes[1], $errors];
        }

        $results = [];

        foreach ($procs as [$proc, $stdout, $errors]) {
            $line = trim((string) stream_get_contents($stdout));
            fclose($stdout);
            $code = proc_close($proc);
            $decoded = json_decode($line, true);

            if ($code !== 0 || ! is_array($decoded)) {
                throw new RuntimeException("Worker failed (exit {$code}): ".(string) @file_get_contents($errors).' | out: '.$line);
            }

            $results[] = $decoded;
        }

        return $results;
    }

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? self::removeTree($path) : unlink($path);
        }

        rmdir($dir);
    }
}
