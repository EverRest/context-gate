<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Spec 011 US3, FR-008: a recorded review finding (tests/findings.php) has a test that carries its group, and every
 * finding group in the tests is recorded. A test in tests/Concurrency (opt-in) counts only when its record says so.
 */
class FindingRecordsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/context-gate-findings-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/tests/Unit', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
        parent::tearDown();
    }

    public function test_every_recorded_finding_is_guarded_by_a_test_and_no_group_is_unrecorded(): void
    {
        $root = dirname(__DIR__, 2);
        $findings = require $root.'/tests/findings.php';

        $this->assertSame([], $this->violations($root, $findings));
    }

    public function test_a_finding_group_without_a_record_is_reported(): void
    {
        $this->writeTest('tests/Unit/Guard.php', 'finding-left-out');

        $this->assertSame(
            ['finding-left-out is used by tests/Unit/Guard.php but has no record in tests/findings.php'],
            $this->violations($this->dir, []),
        );
    }

    public function test_a_recorded_finding_whose_test_is_gone_is_reported_by_name(): void
    {
        $this->writeTest('tests/Unit/Other.php', null);

        $this->assertSame(
            ['finding-gone has no test with its group under tests/'],
            $this->violations($this->dir, ['gone' => $this->record()]),
        );
    }

    public function test_a_group_in_an_opt_in_suite_counts_only_when_the_record_says_so(): void
    {
        $this->writeTest('tests/Concurrency/Race.php', 'finding-race');

        $this->assertSame(
            ['finding-race has no test with its group under tests/ (tests/Concurrency is opt-in: set opt_in)'],
            $this->violations($this->dir, ['race' => $this->record()]),
        );
        $this->assertSame([], $this->violations($this->dir, ['race' => $this->record(optIn: true)]));
    }

    public function test_a_record_needs_a_date_and_a_summary(): void
    {
        $this->writeTest('tests/Unit/Guard.php', 'finding-bare');

        $this->assertSame(
            ['finding-bare: record needs a fixed date (YYYY-MM-DD) and a one-line summary'],
            $this->violations($this->dir, ['bare' => ['fixed' => '', 'summary' => '']]),
        );
    }

    /**
     * @param array<string, array{fixed?: string, summary?: string, opt_in?: bool}> $findings
     *
     * @return list<string>
     */
    private function violations(string $root, array $findings): array
    {
        $groups = $this->groupsByFinding($root);
        $out = [];
        foreach ($groups as $key => $files) {
            if (!array_key_exists($key, $findings)) {
                $out[] = "finding-{$key} is used by {$files[0]} but has no record in tests/findings.php";
            }
        }
        foreach ($findings as $key => $record) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $record['fixed'] ?? '') !== 1 || trim($record['summary'] ?? '') === '') {
                $out[] = "finding-{$key}: record needs a fixed date (YYYY-MM-DD) and a one-line summary";
                continue;
            }
            $allowed = array_filter(
                $groups[$key] ?? [],
                static fn (string $file): bool => ($record['opt_in'] ?? false) || !str_starts_with($file, 'tests/Concurrency/'),
            );
            if ($allowed === []) {
                $suffix = ($groups[$key] ?? []) === [] ? '' : ' (tests/Concurrency is opt-in: set opt_in)';
                $out[] = "finding-{$key} has no test with its group under tests/".$suffix;
            }
        }

        return $out;
    }

    /** @return array<string, list<string>> finding key => files that carry its group attribute */
    private function groupsByFinding(string $root): array
    {
        $groups = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/tests', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f instanceof \SplFileInfo || $f->getExtension() !== 'php') {
                continue;
            }
            $rel = substr($f->getPathname(), strlen($root) + 1);
            foreach ($this->attributeTexts((string) file_get_contents($f->getPathname())) as $text) {
                if (preg_match("/^#\\[Group\\('finding-([a-z0-9-]+)'\\)/", $text, $m) === 1) {
                    $groups[$m[1]][] = $rel;
                }
            }
        }
        ksort($groups);

        return $groups;
    }

    /**
     * Attribute source only: a group written inside a string literal (as in this file's fixtures) is not one.
     *
     * @return list<string>
     */
    private function attributeTexts(string $code): array
    {
        $texts = [];
        $collecting = null;
        foreach (token_get_all($code) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_ATTRIBUTE) {
                $collecting = '';
            }
            if ($collecting !== null) {
                $collecting .= $text;
                if ($text === ']') {
                    $texts[] = $collecting;
                    $collecting = null;
                }
            }
        }

        return $texts;
    }

    /** @return array{fixed: string, summary: string, opt_in?: bool} */
    private function record(bool $optIn = false): array
    {
        $record = ['fixed' => '2026-11-01', 'summary' => 'the rule the fix restored'];

        return $optIn ? $record + ['opt_in' => true] : $record;
    }

    private function writeTest(string $rel, ?string $group): void
    {
        $attribute = $group === null ? '' : "#[Group('{$group}')]\n";
        $path = $this->dir.'/'.$rel;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, "<?php\n\n{$attribute}final class Guard {}\n");
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
