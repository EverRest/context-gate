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

    /** A src tree that holds only the file the clover fixtures measure (Pipeline/Caps.php). */
    private string $src;

    protected function setUp(): void
    {
        parent::setUp();
        $this->summary = sys_get_temp_dir().'/context-gate-summary-'.bin2hex(random_bytes(6)).'.md';
        $this->src = sys_get_temp_dir().'/context-gate-src-'.bin2hex(random_bytes(6)).'/src';
        mkdir($this->src.'/Pipeline', 0777, true);
        $this->writeSrc('Pipeline/Caps.php');
    }

    protected function tearDown(): void
    {
        if (is_file($this->summary)) {
            unlink($this->summary);
        }
        $this->remove(dirname($this->src));
        parent::tearDown();
    }

    public function test_a_src_file_missing_from_the_report_fails_and_is_named(): void
    {
        $this->writeSrc('Pipeline/Gone.php');

        [$ok, $line] = coverage_floor_check(self::FIXTURES.'/clover-93.xml', null, $this->src);

        $this->assertFalse($ok);
        $this->assertSame('not in the coverage report: src/Pipeline/Gone.php (every src file must be measured)', $line);
    }

    public function test_coverage_at_the_floor_passes_and_prints_the_line(): void
    {
        [$ok, $line] = coverage_floor_check(self::FIXTURES.'/clover-93.xml', null, $this->src);

        $this->assertTrue($ok);
        $this->assertSame('line coverage 93.0% (floor 92%)', $line);
    }

    public function test_coverage_below_the_floor_fails_and_prints_both_numbers(): void
    {
        [$ok, $line] = coverage_floor_check(self::FIXTURES.'/clover-91.xml', null, $this->src);

        $this->assertFalse($ok);
        $this->assertSame('line coverage 91.0% (floor 92%): below the floor', $line);
    }

    public function test_missing_report_fails_and_never_passes_with_no_measurement(): void
    {
        [$ok, $line] = coverage_floor_check(self::FIXTURES.'/does-not-exist.xml', null, $this->src);

        $this->assertFalse($ok);
        $this->assertStringContainsString('no coverage measured', $line);
    }

    public function test_report_with_no_src_statements_fails(): void
    {
        [$ok, $line] = coverage_floor_check(self::FIXTURES.'/clover-empty.xml', null, $this->src);

        $this->assertFalse($ok);
        $this->assertStringContainsString('no coverage measured', $line);
    }

    public function test_the_line_is_appended_to_the_step_summary_when_given(): void
    {
        coverage_floor_check(self::FIXTURES.'/clover-93.xml', $this->summary, $this->src);
        coverage_floor_check(self::FIXTURES.'/clover-91.xml', $this->summary, $this->src);

        $this->assertSame(
            "line coverage 93.0% (floor 92%)\nline coverage 91.0% (floor 92%): below the floor\n",
            file_get_contents($this->summary),
        );
    }

    private function writeSrc(string $rel): void
    {
        file_put_contents($this->src.'/'.$rel, "<?php\n");
    }

    private function remove(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
