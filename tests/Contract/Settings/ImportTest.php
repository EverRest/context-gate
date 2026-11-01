<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Settings\ConfigVersionStore;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/** spec 007 T042 (US6, FR-013, SC-004): an invalid import changes nothing; a valid one becomes active and is audited. */
class ImportTest extends TestCase
{
    private string $dir;

    private string $file;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $id = bin2hex(random_bytes(6));
        $this->dir = sys_get_temp_dir().'/cg-import-'.$id;
        $this->file = sys_get_temp_dir().'/cg-import-'.$id.'.json';
        $this->log = sys_get_temp_dir().'/cg-import-'.$id.'.log';

        app()->useStoragePath($this->dir);
        config(['logging.channels.context-gate' => ['driver' => 'single', 'path' => $this->log]]);
        Log::forgetChannel('context-gate');
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->log] as $path) {
            @unlink($path);
        }

        if (is_dir($this->dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    public function test_an_invalid_file_is_refused_with_every_error_and_changes_nothing(): void
    {
        $this->writeFile(['profile' => 'balanced', 'bogus_key' => 1, 'firewall' => ['limits' => ['per_call_bytes' => 70000]]]);
        config(['app.env' => 'production']);

        $exit = Artisan::call('gate:config:import', ['file' => $this->file]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('profile', $output);
        $this->assertStringContainsString('bogus_key', $output);
        $this->assertStringContainsString('firewall.limits.per_call_bytes', $output);
        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function test_an_invalid_file_leaves_an_existing_index_and_versions_unchanged(): void
    {
        $this->writeFile(['profile' => 'strict']);
        $this->assertSame(0, Artisan::call('gate:config:import', ['file' => $this->file]));
        $before = $this->snapshot();

        $this->writeFile(['firewall' => ['limits' => ['per_call_bytes' => 70000]]]);
        $this->assertSame(1, Artisan::call('gate:config:import', ['file' => $this->file]));

        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_valid_file_becomes_active_and_writes_one_audit_line(): void
    {
        $document = ['profile' => 'strict', 'firewall' => ['limits' => ['per_call_bytes' => 16384]]];
        $this->writeFile($document);

        $exit = Artisan::call('gate:config:import', ['file' => $this->file]);

        $this->assertSame(0, $exit);
        $this->assertSame(ConfigVersionStore::hashOf($document), (new ConfigVersionStore($this->dir.'/app/context-gate/config'))->active());
        $this->assertCount(1, array_filter(file($this->log) ?: [], static fn (string $line): bool => str_contains($line, 'config.applied')));
    }

    /** @param array<string, mixed> $document */
    private function writeFile(array $document): void
    {
        file_put_contents($this->file, json_encode($document, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> relative path => contents */
    private function snapshot(): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS)) as $item) {
            $files[substr($item->getPathname(), strlen($this->dir))] = (string) file_get_contents($item->getPathname());
        }

        ksort($files);

        return $files;
    }
}
