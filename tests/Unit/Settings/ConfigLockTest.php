<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Settings\ConfigLock;
use ContextGate\Settings\ConfigVersionStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** spec 007 F5: a configuration lock that cannot be taken refuses the change; nothing is written. */
class ConfigLockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/cg-lock-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        ConfigLock::$lockWith = null;

        if (is_dir($this->dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    public function test_a_failed_lock_throws_naming_the_lock_file_and_closes_the_handle(): void
    {
        $handle = null;
        ConfigLock::$lockWith = static function ($h) use (&$handle): bool {
            $handle = $h;

            return false;
        };

        $ran = false;

        try {
            ConfigLock::run($this->dir, function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('a failed lock did not throw');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString($this->dir.'/.lock', $error->getMessage());
        }

        $this->assertFalse($ran, 'the callback ran without the lock');
        $this->assertSame('Unknown', get_resource_type($handle), 'the lock handle was left open');
    }

    public function test_a_save_under_a_failed_lock_writes_nothing_and_keeps_the_active_version(): void
    {
        $store = new ConfigVersionStore($this->dir);
        $active = $store->save(['log_level' => 'info'], 'cli');

        ConfigLock::$lockWith = static fn ($h): bool => false;

        try {
            $store->save(['log_level' => 'notice'], 'cli');
            $this->fail('a save under a failed lock did not throw');
        } catch (RuntimeException) {
        }

        $this->assertSame($active, $store->active());
        $this->assertCount(1, glob($this->dir.'/versions/*.json') ?: []);
    }
}
