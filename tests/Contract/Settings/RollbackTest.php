<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Settings\ConfigVersionStore;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/** spec 007 T046 (US6, FR-014, R3): rollback restores an earlier version and refuses one above a lowered cap. */
class RollbackTest extends TestCase
{
    private string $dir;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $id = bin2hex(random_bytes(6));
        $this->dir = sys_get_temp_dir().'/cg-rollback-'.$id;
        $this->log = sys_get_temp_dir().'/cg-rollback-'.$id.'.log';

        app()->useStoragePath($this->dir);
        config(['logging.channels.context-gate' => ['driver' => 'single', 'path' => $this->log]]);
        Log::forgetChannel('context-gate');
    }

    protected function tearDown(): void
    {
        @unlink($this->log);

        if (is_dir($this->dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    public function test_rollback_makes_the_earlier_version_active_and_audits_it(): void
    {
        $store = $this->store();
        $a = $store->save(['log_level' => 'info'], 'cli');
        $store->save(['log_level' => 'notice'], 'cli');

        $exit = Artisan::call('gate:config:rollback', ['hash' => $a]);

        $this->assertSame(0, $exit);
        $this->assertSame($a, $this->store()->active());
        $this->assertStringContainsString('config.rollback', (string) file_get_contents($this->log));
    }

    public function test_an_unknown_hash_exits_one_and_changes_nothing(): void
    {
        $active = $this->store()->save(['log_level' => 'info'], 'cli');

        $exit = Artisan::call('gate:config:rollback', ['hash' => hash('sha256', 'not stored')]);

        $this->assertSame(1, $exit);
        $this->assertSame($active, $this->store()->active());
    }

    public function test_a_version_above_a_lowered_cap_is_refused_and_names_the_key(): void
    {
        $store = $this->store();
        $active = $store->save(['log_level' => 'info'], 'cli');
        $oldDocument = ['firewall' => ['limits' => ['per_call_bytes' => 70000]]];
        $old = hash('sha256', 'written directly');
        is_dir($this->dir.'/app/context-gate/config/versions') || mkdir($this->dir.'/app/context-gate/config/versions', 0700, true);
        file_put_contents(
            $this->dir.'/app/context-gate/config/versions/'.$old.'.json',
            json_encode(['hash' => $old, 'document' => $oldDocument], JSON_THROW_ON_ERROR),
        );

        $exit = Artisan::call('gate:config:rollback', ['hash' => $old]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('firewall.limits.per_call_bytes', Artisan::output());
        $this->assertSame($active, $this->store()->active());
    }

    public function test_a_version_valid_alone_but_invalid_merged_with_the_file_config_is_refused(): void
    {
        $store = $this->store();
        $a = $store->save(['log_level' => 'info'], 'cli');
        $active = $store->save(['log_level' => 'notice'], 'cli');
        app()->instance('context-gate.file', ['firewall' => ['limits' => ['per_call_bytes' => 999999]]]);

        $this->assertSame(1, Artisan::call('gate:config:rollback', ['hash' => $a]));
        $this->assertStringContainsString('firewall.limits.per_call_bytes', Artisan::output());
        $this->assertSame($active, $this->store()->active());
    }

    public function test_a_log_channel_that_throws_leaves_the_active_version_unchanged(): void
    {
        $store = $this->store();
        $a = $store->save(['log_level' => 'info'], 'cli');
        $active = $store->save(['log_level' => 'notice'], 'cli');
        file_put_contents($this->log, 'x');
        config(['logging.channels.context-gate' => ['driver' => 'single', 'path' => $this->log.'/audit.log']]);
        Log::forgetChannel('context-gate');

        $this->assertSame(1, Artisan::call('gate:config:rollback', ['hash' => $a]));
        $this->assertStringContainsString('audit line', Artisan::output());
        $this->assertSame($active, $this->store()->active());
    }

    private function store(): ConfigVersionStore
    {
        return ConfigVersionStore::fromApp();
    }
}
