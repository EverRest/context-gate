<?php

declare(strict_types=1);

namespace ContextGate\Tests\Concurrency;

use ContextGate\Settings\ConfigVersionStore;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * spec 007 F5: a save waits for the configuration lock held by another process, and both versions land in the index
 * with no orphan version file. Opt-in, see ConcurrencyTestCase. Works only in the temporary directory of the test.
 */
#[Group('concurrency')]
class ConfigLockProcessTest extends ConcurrencyTestCase
{
    private const HOLD_SECONDS = 1.5;

    public function test_a_save_waits_while_another_process_holds_the_config_lock(): void
    {
        $dir = $this->base.'/config';
        mkdir($dir, 0700, true);

        $holder = proc_open(
            [PHP_BINARY, __DIR__.'/Workers/config-lock-holder.php', $dir.'/.lock', (string) self::HOLD_SECONDS],
            [1 => ['pipe', 'w'], 2 => ['file', $this->base.'/holder.err', 'w']],
            $pipes,
        );

        if (! is_resource($holder)) {
            throw new RuntimeException('Could not start the lock holder.');
        }

        $this->assertSame("locked\n", fgets($pipes[1]), 'the holder did not take the lock');

        $store = new ConfigVersionStore($dir);
        $started = microtime(true);
        $first = $store->save(['log_level' => 'info'], 'cli');
        $waited = microtime(true) - $started;
        $second = $store->save(['log_level' => 'notice'], 'cli');

        $this->assertSame("released\n", stream_get_contents($pipes[1]), 'the holder did not release the lock');
        fclose($pipes[1]);
        $this->assertSame(0, proc_close($holder));

        $this->assertGreaterThanOrEqual(1.0, $waited, 'the save did not wait for the lock held by the other process');

        $index = json_decode((string) file_get_contents($dir.'/index.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($second, $index['active']);
        $this->assertSame([$first, $second], array_column($index['versions'], 'hash'));
        $this->assertCount(2, glob($dir.'/versions/*.json') ?: [], 'a version file was left behind without an index entry');
    }
}
