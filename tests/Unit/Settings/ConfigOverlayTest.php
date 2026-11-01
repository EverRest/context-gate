<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Settings\ActiveSettings;
use ContextGate\Settings\ConfigOverlay;
use ContextGate\Settings\ConfigVersionStore;
use PHPUnit\Framework\TestCase;

/** spec 007 T048 (US6, R1): the active stored version is merged over the file config; an invalid one makes settings invalid. */
class ConfigOverlayTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/cg-overlay-'.bin2hex(random_bytes(6));
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

    public function test_the_active_version_is_returned_over_the_file_config(): void
    {
        $store = new ConfigVersionStore($this->dir);
        $store->save(['log_level' => 'notice'], 'cli');

        $merged = ConfigOverlay::apply(['log_level' => 'info', 'profile' => 'strict'], $store);

        $this->assertSame(['log_level' => 'notice', 'profile' => 'strict'], $merged);
    }

    public function test_with_no_active_version_the_file_config_is_returned_unchanged(): void
    {
        $file = ['log_level' => 'info', 'profile' => 'strict'];

        $this->assertSame($file, ConfigOverlay::apply($file, new ConfigVersionStore($this->dir)));
    }

    public function test_a_list_in_the_version_replaces_the_file_list_instead_of_merging_into_it(): void
    {
        $store = new ConfigVersionStore($this->dir);
        $store->save(['callers' => ['a' => ['tools' => ['z']]]], 'cli');

        $merged = ConfigOverlay::apply(['callers' => ['a' => ['tools' => ['x', 'y']]]], $store);

        $this->assertSame(['z'], $merged['callers']['a']['tools']);
    }

    public function test_an_active_version_above_a_cap_makes_the_active_settings_invalid(): void
    {
        $store = new ConfigVersionStore($this->dir);
        $store->save(['firewall' => ['limits' => ['per_call_bytes' => 70000]]], 'cli');

        $merged = ConfigOverlay::apply(['profile' => 'strict'], $store);

        $settings = new ActiveSettings($merged, 'testing');
        $this->assertFalse($settings->valid());
        $this->assertSame(['firewall.limits.per_call_bytes'], array_column($settings->violations(), 'key'));
    }

    public function test_an_unreadable_index_leaves_the_file_config_and_reports_it_without_throwing(): void
    {
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir.'/index.json', '{not json');
        $file = ['profile' => 'strict'];

        $this->assertSame($file, ConfigOverlay::apply($file, new ConfigVersionStore($this->dir)));
        $this->assertSame(['versions/index.json'], array_column(ConfigOverlay::violations(new ConfigVersionStore($this->dir)), 'key'));
    }

    public function test_an_active_hash_with_a_missing_version_file_is_reported_without_the_hash(): void
    {
        $store = new ConfigVersionStore($this->dir);
        $hash = $store->save(['log_level' => 'notice'], 'cli');
        unlink($this->dir.'/versions/'.$hash.'.json');

        $violations = ConfigOverlay::violations(new ConfigVersionStore($this->dir));

        $this->assertSame(['versions'], array_column($violations, 'key'));
        $this->assertStringNotContainsString($hash, json_encode($violations, JSON_THROW_ON_ERROR));
    }

    public function test_a_healthy_store_reports_no_violation(): void
    {
        $store = new ConfigVersionStore($this->dir);
        $store->save(['log_level' => 'notice'], 'cli');

        $this->assertSame([], ConfigOverlay::violations($store));
    }
}
