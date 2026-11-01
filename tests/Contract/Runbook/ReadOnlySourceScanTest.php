<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use PHPUnit\Framework\TestCase;

/** spec 018 FR-005, research R5: no file of the runbook code calls a write-like method. */
class ReadOnlySourceScanTest extends TestCase
{
    private const WRITE_CALL = '/(?:->|::|\b)(dispatch|put|forget|increment|insert|update|delete|send|post|call|artisan|save)\s*\(/';

    /** @return list<string> "file:line" for each write-like call found in the code */
    private static function findWrites(string $code): array
    {
        $hits = [];

        foreach (preg_split('/\R/', $code) ?: [] as $number => $line) {
            if (preg_match(self::WRITE_CALL, $line) === 1) {
                $hits[] = ($number + 1).': '.trim($line);
            }
        }

        return $hits;
    }

    public function test_the_scanner_finds_a_write_call(): void
    {
        $this->assertSame(['1: Cache::put($key, 1);'], self::findWrites('Cache::put($key, 1);'));
        $this->assertSame(['1: $queue->dispatch($job);'], self::findWrites('$queue->dispatch($job);'));
    }

    public function test_no_runbook_source_file_calls_a_write_method(): void
    {
        $root = dirname(__DIR__, 3).'/src';
        $files = array_merge(glob($root.'/Runbook/*.php') ?: [], [$root.'/Source/RunbookTool.php']);

        $this->assertNotEmpty($files);

        $found = [];

        foreach ($files as $file) {
            foreach (self::findWrites((string) file_get_contents($file)) as $hit) {
                $found[] = basename($file).':'.$hit;
            }
        }

        $this->assertSame([], $found);
    }
}
