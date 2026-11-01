<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\TestCase;
use FilesystemIterator;
use Illuminate\Support\Facades\Artisan;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** T047, User Story 5 scenario 2 and FR-017: gate:canary changes no project file (SC-005). */
class CanaryNoWriteTest extends TestCase
{
    public function test_project_file_hashes_are_identical_before_and_after_gate_canary(): void
    {
        $before = $this->hashes();

        Artisan::call('gate:canary');

        $this->assertSame($before, $this->hashes());
    }

    /** @return array<string, string> file path to content hash, for the directories a placement could touch */
    private function hashes(): array
    {
        $hashes = [];

        foreach (['app', 'config', 'resources', 'routes'] as $directory) {
            $root = base_path($directory);

            if (! is_dir($root)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                // An unreadable file keeps a marker, so a file that becomes unreadable still changes the comparison.
                $hashes[(string) $file] = hash_file('sha256', (string) $file) ?: 'unreadable';
            }
        }

        ksort($hashes);

        return $hashes;
    }
}
