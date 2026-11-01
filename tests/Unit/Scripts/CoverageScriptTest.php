<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * Spec 011 US2, FR-007: a coverage run that writes no report must fail, not reuse a clover left by an earlier run. The
 * composer `coverage` script deletes the old report before PHPUnit writes a new one.
 */
class CoverageScriptTest extends TestCase
{
    public function test_the_coverage_script_deletes_the_old_clover_before_phpunit_runs(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/composer.json'), true);
        $steps = $composer['scripts']['coverage'] ?? [];

        $this->assertCount(3, $steps);
        $this->assertStringContainsString("unlink('build/coverage/clover.xml')", $steps[0]);
        $this->assertStringContainsString('phpunit', $steps[1]);
        $this->assertStringContainsString('--coverage-clover build/coverage/clover.xml', $steps[1]);
        $this->assertStringContainsString('coverage-floor.php', $steps[2]);
    }
}
