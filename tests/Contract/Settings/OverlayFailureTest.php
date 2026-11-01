<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\ContextGateServiceProvider;
use ContextGate\Settings\ActiveSettings;
use ContextGate\Settings\ConfigVersionStore;
use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/**
 * spec 007 amendment 2026-11-01: an unreadable index or a missing active version file does not stop boot. Calls are
 * refused with config.invalid and gate:check names the cause.
 */
class OverlayFailureTest extends TestCase
{
    use GateCallHarness;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/cg-overlay-failure-'.bin2hex(random_bytes(6));
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

    public function test_a_corrupt_index_does_not_stop_the_commands_and_gate_check_names_it(): void
    {
        $this->corruptIndex();
        $this->boot();

        $check = Artisan::call('gate:check');
        $this->assertSame(1, $check);
        $this->assertStringContainsString('versions/index.json', Artisan::output());

        $file = $this->dir.'/bad.json';
        file_put_contents($file, json_encode(['profile' => 'strict'], JSON_THROW_ON_ERROR));
        $this->assertSame(1, Artisan::call('gate:config:import', ['file' => $file]));
        $this->assertSame('{not json', file_get_contents($this->dir.'/app/context-gate/config/index.json'));
    }

    public function test_a_corrupt_index_refuses_a_call_with_config_invalid(): void
    {
        $this->corruptIndex();
        $this->boot();
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public', 'note' => 'internal', 'email' => 'personal']]);

        $result = $this->callTool('probe', ['text' => 'x']);

        $this->assertTrue($result['isError']);
        $this->assertFalse(ProbeTool::$called);
        $receipt = json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('config.invalid', $receipt['reason']);
    }

    public function test_a_missing_active_version_file_refuses_calls_and_gate_check_names_versions(): void
    {
        $hash = ConfigVersionStore::fromApp()->save(['log_level' => 'notice'], 'cli');
        unlink($this->dir.'/app/context-gate/config/versions/'.$hash.'.json');
        $this->boot();
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['status' => 'public', 'note' => 'internal', 'email' => 'personal']]);

        $this->assertFalse(app(ActiveSettings::class)->valid());
        $this->assertSame(1, Artisan::call('gate:check'));
        $this->assertStringContainsString('versions', Artisan::output());
        $this->assertStringNotContainsString($hash, Artisan::output());
        $this->assertTrue($this->callTool('probe', ['text' => 'x'])['isError']);
        $this->assertFalse(ProbeTool::$called);
    }

    /** Boots the provider again on the temporary storage and forgets the settings resolved before it. */
    private function boot(): void
    {
        (new ContextGateServiceProvider(app()))->register();
        app()->forgetInstance(ActiveSettings::class);
    }

    private function corruptIndex(): void
    {
        mkdir($this->dir.'/app/context-gate/config', 0700, true);
        file_put_contents($this->dir.'/app/context-gate/config/index.json', '{not json');
    }
}
