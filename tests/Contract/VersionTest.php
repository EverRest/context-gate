<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\ContextGate;
use PHPUnit\Framework\TestCase;

/** FR-018, SC-006: the version constant matches its changelog entry, and that entry has content. */
class VersionTest extends TestCase
{
    public function test_version_constant_matches_the_changelog_heading(): void
    {
        $this->assertContains(
            '## ['.ContextGate::VERSION.']',
            $this->lines(),
            'CHANGELOG.md has no heading exactly "## ['.ContextGate::VERSION.']"'
        );
    }

    public function test_release_section_has_a_body_with_a_bullet(): void
    {
        $bullets = array_filter(
            $this->section('## ['.ContextGate::VERSION.']'),
            static fn (string $line): bool => str_starts_with($line, '- ')
        );

        $this->assertNotEmpty($bullets, 'The '.ContextGate::VERSION.' section of CHANGELOG.md has no bullet');
    }

    /** @return list<string> */
    private function lines(): array
    {
        $file = dirname(__DIR__, 2).'/CHANGELOG.md';
        $this->assertFileExists($file);

        return explode("\n", (string) file_get_contents($file));
    }

    /** @return list<string> the lines between the heading and the next level-2 heading */
    private function section(string $heading): array
    {
        $lines = $this->lines();
        $start = array_search($heading, $lines, true);
        $this->assertIsInt($start, "Missing heading: {$heading}");

        $body = [];
        foreach (array_slice($lines, $start + 1) as $line) {
            if (str_starts_with($line, '## ')) {
                break;
            }
            $body[] = $line;
        }

        return $body;
    }
}
