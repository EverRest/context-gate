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

    public function test_lightweight_tag_fails_the_annotated_guard(): void
    {
        $this->commitAll('First release.');
        $this->git('tag', 'v0.1.0');

        [$code, $output] = $this->runTagGuard('annotated', 'v0.1.0');

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('check-release: FAILED (annotated guard):', $output);
    }

    public function test_annotated_tag_on_the_tip_passes_annotated_and_tip(): void
    {
        $this->publishTip('v0.1.0');

        [$annotatedCode, $annotatedOutput] = $this->runTagGuard('annotated', 'v0.1.0');
        [$tipCode, $tipOutput] = $this->runTagGuard('tip', 'v0.1.0');

        $this->assertSame(0, $annotatedCode, $annotatedOutput);
        $this->assertSame(0, $tipCode, $tipOutput);
    }

    public function test_tag_on_a_commit_behind_origin_master_fails_the_tip_guard(): void
    {
        $this->commitAll('First release.');
        $this->git('tag', '-a', 'v0.1.0', '-m', 'v0.1.0');
        $this->git('update-ref', 'refs/remotes/origin/master', 'HEAD');
        $this->write('NOTES.md', "later\n");
        $this->commitAll('Later work.');
        $this->git('update-ref', 'refs/remotes/origin/master', 'HEAD');

        [$code, $output] = $this->runTagGuard('tip', 'v0.1.0');

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('check-release: FAILED (tip guard):', $output);
    }

    public function test_tip_guard_fails_when_origin_master_is_missing(): void
    {
        $this->publishTip('v0.1.0');
        $this->git('update-ref', '-d', 'refs/remotes/origin/master');

        [$code, $output] = $this->runTagGuard('tip', 'v0.1.0');

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('check-release: FAILED (tip guard):', $output);
    }

    public function test_tag_whose_version_differs_from_the_constant_fails_the_version_guard(): void
    {
        $this->publishTip('v0.2.0');

        [$code, $output] = $this->runTagGuard('version', 'v0.2.0');

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('check-release: FAILED (version guard):', $output);
    }

    public function test_tag_with_a_matching_version_and_changelog_passes_both_guards(): void
    {
        $this->publishTip('v0.1.0');

        [$versionCode, $versionOutput] = $this->runTagGuard('version', 'v0.1.0');
        [$changelogCode, $changelogOutput] = $this->runTagGuard('changelog', 'v0.1.0');

        $this->assertSame(0, $versionCode, $versionOutput);
        $this->assertSame(0, $changelogCode, $changelogOutput);
    }

    /** A git repository in the scratch directory with one commit, an annotated v0.1.0 tag and origin/master on it. */
    private function publishTip(string $tag): void
    {
        $this->commitAll('First release.');
        $this->git('tag', '-a', $tag, '-m', $tag);
        $this->git('update-ref', 'refs/remotes/origin/master', 'HEAD');
    }

    private function commitAll(string $message): void
    {
        if (! is_dir($this->dir.'/.git')) {
            $this->git('init', '-q', '-b', 'master');
        }
        $this->git('add', '-A');
        $this->git('commit', '-q', '-m', $message);
    }

    private function git(string ...$args): void
    {
        $process = new Process(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false', ...$args], $this->dir);
        $process->mustRun();
    }

    /** @return array{int, string} */
    private function runTagGuard(string $guard, string $tag): array
    {
        $process = new Process(['bash', dirname(__DIR__, 2).'/'.self::SCRIPT, $guard, $tag], $this->dir);
        $process->run();

        return [$process->getExitCode() ?? -1, $process->getOutput().$process->getErrorOutput()];
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
