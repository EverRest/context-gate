<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/scripts/check-spec-refs.php';

/**
 * Spec 011 US5, FR-015 and FR-016: comments under src/ hold no spec, requirement, task or decision ID. Only comment
 * tokens are scanned, so the same text in code or in a string literal is not reported, and tests and specs are not.
 */
class SpecRefsCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/context-gate-refs-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/src/Pipeline', 0777, true);
        mkdir($this->dir.'/tests/Unit', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
        parent::tearDown();
    }

    public function test_every_kind_of_id_in_a_comment_is_reported_with_its_path_and_line(): void
    {
        $this->write('src/Pipeline/Fixture.php', implode("\n", [
            '<?php',
            '// Decided by spec 007 R9.',
            '/* FR-004 and T012 */',
            '/**',
            ' * See research R8 and US3.',
            ' */',
        ]));

        $this->assertSame([
            'src/Pipeline/Fixture.php:2: spec 007',
            'src/Pipeline/Fixture.php:2: R9',
            'src/Pipeline/Fixture.php:3: FR-004',
            'src/Pipeline/Fixture.php:3: T012',
            'src/Pipeline/Fixture.php:5: research R8',
            'src/Pipeline/Fixture.php:5: US3',
        ], spec_ref_violations($this->dir));
    }

    public function test_the_same_text_in_code_or_in_a_string_is_not_reported(): void
    {
        $this->write('src/Pipeline/Fixture.php', implode("\n", [
            '<?php',
            "\$s = 'spec 007 and FR-004';",
            '$d = D36;',
            '// a hidden tool answers like an unknown tool',
        ]));

        $this->assertSame([], spec_ref_violations($this->dir));
    }

    public function test_files_under_tests_are_not_checked(): void
    {
        $this->write('tests/Unit/Fixture.php', "<?php\n// spec 007 and FR-004\n");

        $this->assertSame([], spec_ref_violations($this->dir));
    }

    public function test_the_real_source_has_no_references(): void
    {
        $this->assertSame([], spec_ref_violations(dirname(__DIR__, 3)));
    }

    private function write(string $rel, string $content): void
    {
        file_put_contents($this->dir.'/'.$rel, $content);
    }

    private function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
