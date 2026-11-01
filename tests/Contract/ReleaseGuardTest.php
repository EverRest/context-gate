<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * User Story 5 scenarios 2 and 3, FR-019: scripts/check-release.sh fails on a missing changelog heading, a stale
 * version and the reporting placeholder. Every case runs in a scratch copy; the real files are never changed.
 */
class ReleaseGuardTest extends TestCase
{
    private const SCRIPT = 'scripts/check-release.sh';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/context-gate-release-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/src', 0777, true);
        $this->write('src/ContextGate.php', "<?php\n\nfinal class ContextGate\n{\n    public const VERSION = '0.1.0';\n}\n");
        $this->write('CHANGELOG.md', "# Changelog\n\n## [0.1.0]\n\n- First release.\n\n## Unreleased\n");
        $this->write('SECURITY.md', "# Security Policy\n\nReport to a real address.\n");
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
        parent::tearDown();
    }

    public function test_complete_copy_passes_every_guard(): void
    {
        [$code, $output] = $this->runGuard();

        $this->assertSame(0, $code, $output);
    }

    public function test_changelog_without_the_version_heading_fails(): void
    {
        $changelog = $this->realFile('CHANGELOG.md');
        $this->write('CHANGELOG.md', str_replace("## [0.1.0]\n", '', $changelog));
        $this->write('SECURITY.md', $this->realFile('SECURITY.md'));

        [$code, $output] = $this->runGuard();

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('changelog', $output);
    }

    public function test_security_md_with_the_reporting_placeholder_fails(): void
    {
        $this->write('SECURITY.md', str_replace('GitHub\'s private vulnerability reporting', '<REPORTING_ADDRESS>', $this->realFile('SECURITY.md')));

        [$code, $output] = $this->runGuard();

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('placeholder', $output);
    }

    public function test_newest_changelog_heading_must_be_the_version_constant(): void
    {
        $this->write('CHANGELOG.md', "# Changelog\n\n## [0.2.0]\n\n- Next.\n\n## [0.1.0]\n\n- First release.\n");

        [$code, $output] = $this->runGuard();

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('version', $output);
    }

    /** @return array{int, string} */
    private function runGuard(): array
    {
        $process = new Process(['bash', dirname(__DIR__, 2).'/'.self::SCRIPT, $this->dir]);
        $process->run();

        return [$process->getExitCode() ?? -1, $process->getOutput().$process->getErrorOutput()];
    }

    private function realFile(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$name);
    }

    private function write(string $path, string $contents): void
    {
        file_put_contents($this->dir.'/'.$path, $contents);
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path.'/'.$entry);
                }
            }
            rmdir($path);

            return;
        }
        if (is_file($path)) {
            unlink($path);
        }
    }
}
