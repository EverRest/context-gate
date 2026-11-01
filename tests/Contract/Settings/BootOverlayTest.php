<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\ContextGateServiceProvider;
use ContextGate\Settings\ConfigVersionStore;
use ContextGate\Tests\TestCase;

/** spec 007 T049 (US6, R1): the provider merges the active stored version over the file config when it boots. */
class BootOverlayTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/cg-boot-overlay-'.bin2hex(random_bytes(6));
        app()->useStoragePath($this->dir);
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

    public function test_an_active_version_is_in_config_after_boot(): void
    {
        ConfigVersionStore::fromApp()->save(['log_level' => 'notice'], 'cli');

        (new ContextGateServiceProvider(app()))->register();

        $this->assertSame('notice', config('context-gate.log_level'));
    }
}
