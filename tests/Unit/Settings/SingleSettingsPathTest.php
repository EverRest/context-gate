<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Tests\TestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * spec 009 FR-001, FR-002, SC-001 (research R7): package code outside src/Settings/ never reads the package configuration
 * directly, and never calls the static readers of EffectiveSettings. Every read goes through ActiveSettings.
 */
class SingleSettingsPathTest extends TestCase
{
    private const PATTERNS = [
        '/\bconfig\s*\(\s*[\'"]context-gate/',
        '/\bconfig\s*\(\s*\[\s*[\'"]context-gate/',
        '/\bconfig\(\)\s*->\s*(?:get|set)\s*\(\s*[\'"]context-gate/',
        '/\bConfig::(?:get|set)\s*\(\s*[\'"]context-gate/',
        '/\bEffectiveSettings::/',
    ];

    /** @return list<string> "src/path.php:line" for each direct read outside the settings layer */
    private function directReads(): array
    {
        $root = dirname(__DIR__, 3).'/src';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $found = [];

        foreach ($files as $file) {
            $path = (string) $file;

            if (! str_ends_with($path, '.php') || str_starts_with($path, $root.'/Settings/')) {
                continue;
            }

            foreach (explode("\n", (string) file_get_contents($path)) as $number => $line) {
                foreach (self::PATTERNS as $pattern) {
                    if (preg_match($pattern, $line) === 1) {
                        $found[] = substr($path, strlen(dirname($root)) + 1).':'.($number + 1);

                        break;
                    }
                }
            }
        }

        sort($found);

        return $found;
    }

    public function test_no_file_outside_the_settings_layer_reads_the_package_configuration(): void
    {
        $this->assertSame([], $this->directReads(), 'Direct reads of the package configuration outside src/Settings/.');
    }
}
