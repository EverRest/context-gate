<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Source\DocsPathPolicy;
use PHPUnit\Framework\TestCase;

/**
 * F5: the allowlist compares case exactly; only the denylist ignores case. Needs a real `Docs/` folder, so it
 * runs on a case-sensitive file system (set TMPDIR to one to run it there) and says so when it cannot.
 */
class DocsCaseSensitiveTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/cg-case-' . bin2hex(random_bytes(6));
        mkdir($base . '/app/Docs', 0777, true);
        file_put_contents($base . '/app/Docs/guide.md', "# Guide\n");
        $this->root = (string) realpath($base . '/app');
    }

    protected function tearDown(): void
    {
        DocsTree::remove($this->root);
    }

    public function test_upper_case_folder_is_refused_when_only_the_lower_case_entry_is_allowed(): void
    {
        if (is_dir($this->root . '/docs')) {
            $this->markTestSkipped('case-insensitive file system: Docs/ is the same folder as docs/, so the case-sensitive assertion is skipped.');
        }

        $this->assertSame('allowlist', (new DocsPathPolicy($this->root, ['docs/']))->refusalStep('Docs/guide.md'));
    }

    public function test_upper_case_folder_is_allowed_when_its_own_case_is_allowlisted(): void
    {
        $this->assertNull((new DocsPathPolicy($this->root, ['Docs/']))->refusalStep('Docs/guide.md'));
    }
}
