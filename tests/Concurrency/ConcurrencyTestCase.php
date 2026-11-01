<?php

declare(strict_types=1);

namespace ContextGate\Tests\Concurrency;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Multi-process tests. They run only with CONTEXT_GATE_CONCURRENCY=1 and spawn PHP worker processes that share one
 * file cache store in a temporary directory. Each worker prints one JSON line on stdout.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    /** Seconds the workers wait after spawn, so that they start together (a crude barrier). */
    private const START_DELAY = 1.5;

    protected string $base = '';

    protected string $store = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('CONTEXT_GATE_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Set CONTEXT_GATE_CONCURRENCY=1 to run the multi-process tests.');
        }

        $this->base = sys_get_temp_dir().'/cg-conc-'.bin2hex(random_bytes(6));
        $this->store = $this->base.'/store';
        mkdir($this->store, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->base !== '' && is_dir($this->base)) {
            self::removeTree($this->base);
        }

        parent::tearDown();
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
