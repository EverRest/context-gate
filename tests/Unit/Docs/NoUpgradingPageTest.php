<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * Spec 011 US4, FR-012 and FR-013: no upgrading page and no compatibility notes remain in the shipped documentation.
 * There are no users before 010, so a "Breaking" section or a "superseded" note describes nothing a reader can use.
 */
class NoUpgradingPageTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    public function test_the_upgrading_page_does_not_exist(): void
    {
        $this->assertFileDoesNotExist(self::ROOT.'/docs/upgrading.md');
    }

    public function test_no_shipped_document_links_to_an_upgrading_page(): void
    {
        $this->assertSame([], $this->findMatches('/upgrading/i', ['README.md', 'docs', 'CHANGELOG.md']));
    }

    public function test_no_shipped_document_has_a_superseded_note(): void
    {
        $this->assertSame([], $this->findMatches('/superseded/i', ['README.md', 'docs', 'CHANGELOG.md']));
    }

    public function test_the_changelog_has_no_breaking_section(): void
    {
        $this->assertSame([], $this->findMatches('/breaking/i', ['CHANGELOG.md']));
    }

    /**
     * @param list<string> $paths files or directories relative to the repository root
     *
     * @return list<string> "path:line" of each line that matches
     */
    private function findMatches(string $pattern, array $paths): array
    {
        $found = [];
        foreach ($paths as $rel) {
            $full = self::ROOT.'/'.$rel;
            $files = is_dir($full)
                ? array_map(static fn (\SplFileInfo $f): string => $f->getPathname(), iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS))))
                : [$full];
            foreach ($files as $file) {
                foreach (explode("\n", (string) file_get_contents($file)) as $i => $line) {
                    if (preg_match($pattern, $line) === 1) {
                        $found[] = substr($file, strlen(self::ROOT) + 1).':'.($i + 1);
                    }
                }
            }
        }

        return $found;
    }
}
