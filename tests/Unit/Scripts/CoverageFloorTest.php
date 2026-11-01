<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/scripts/coverage-floor.php';

/**
 * Spec 011 US2, FR-007: the coverage floor reads a clover report of src/ and fails below COVERAGE_FLOOR. The
 * report is a fixture, so no coverage driver is needed to test the floor logic.
 */
class CoverageFloorTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/coverage';

    private string $summary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->summary = sys_get_temp_dir().'/context-gate-summary-'.bin2hex(random_bytes(6)).'.md';
    }

    protected function tearDown(): void
    {
        if (is_file($this->summary)) {
            unlink($this->summary);
        }
        parent::tearDown();
    }

    public function test_coverage_at_the_floor_passes_and_prints_the_line(): void
    {
        [$ok, $line] = coverage_floor_check(self::FIXTURES.'/clover-93.xml');

        $this->assertTrue($ok);
        $this->assertSame('line coverage 93.0% (floor 92%)', $line);
    }

    public function test_coverage_below_the_floor_fails_and_prints_both_numbers(): void
    {
        [$ok, $line] = coverage_floor_check(self::FIXTURES.'/clover-91.xml');

        $this->assertFalse($ok);
        $this->assertSame('line coverage 91.0% (floor 92%): below the floor', $line);
    }

    public function test_missing_report_fails_and_never_passes_with_no_measurement(): void
    {
        [$ok, $line] = coverage_floor_check(self::FIXTURES.'/does-not-exist.xml');

        $this->assertFalse($ok);
        $this->assertStringContainsString('no coverage measured', $line);
    }

    public function test_report_with_no_src_statements_fails(): void
    {
        [$ok, $line] = coverage_floor_check(self::FIXTURES.'/clover-empty.xml');

        $this->assertFalse($ok);
        $this->assertStringContainsString('no coverage measured', $line);
    }

    public function test_the_line_is_appended_to_the_step_summary_when_given(): void
    {
        coverage_floor_check(self::FIXTURES.'/clover-93.xml', $this->summary);
        coverage_floor_check(self::FIXTURES.'/clover-91.xml', $this->summary);

        $this->assertSame(
            "line coverage 93.0% (floor 92%)\nline coverage 91.0% (floor 92%): below the floor\n",
            file_get_contents($this->summary),
        );
    }
}
