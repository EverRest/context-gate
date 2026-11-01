<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Settings\ConfigVersionStore;
use ContextGate\Tests\TestCase;
use RuntimeException;

/** spec 007 F5: a rollback under a configuration lock that cannot be taken refuses the change; nothing is written. */
class ConfigLockRollbackTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/cg-lock-rollback-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    public function test_a_rollback_under_a_failed_lock_keeps_the_active_version(): void
    {
        $store = new ConfigVersionStore($this->dir);
        $first = $store->save(['log_level' => 'info'], 'cli');
        $second = $store->save(['log_level' => 'notice'], 'cli');

        $locked = new ConfigVersionStore($this->dir, static fn ($h): bool => false);

        try {
            $locked->rollback($first, 'production', static function (): void {
            });
            $this->fail('a rollback under a failed lock did not throw');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('The configuration lock could not be taken', $error->getMessage());
        }

        $this->assertSame($second, $store->active());
    }
}
