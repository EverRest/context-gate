<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Source\DocsPathPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DocsPathPolicyAdversarialTest extends TestCase
{
    private const ALLOWED = ['docs/', 'README.md', 'CHANGELOG.md'];

    private string $root;

    protected function setUp(): void
    {
        $this->root = DocsTree::create();
    }

    protected function tearDown(): void
    {
        DocsTree::remove($this->root);
    }

    /** @return array<string, array{string, string|null}> path => refusal step (null when allowed) */
    public static function cases(): array
    {
        return [
            'parent directory escape' => ['../.env', 'root'],
            'dot-dot back into the denylist' => ['docs/../.env', 'denylist'],
            'dotenv inside docs' => ['docs/.env', 'denylist'],
            'hidden markdown file' => ['docs/.hidden.md', 'denylist'],
            'symlink to the env file' => ['docs/link-to-env', 'denylist'],
            'symlink escaping the root' => ['docs/link-outside', 'root'],
            'storage folder' => ['docs/storage/file.md', 'denylist'],
            'vendor folder' => ['docs/vendor/readme.md', 'denylist'],
            'node_modules folder' => ['docs/node_modules/x.md', 'denylist'],
            'key file' => ['docs/keys/server.key', 'denylist'],
            'pem file' => ['docs/certs/a.pem', 'denylist'],
            'auth.json' => ['docs/auth.json', 'denylist'],
            'non-text extension' => ['docs/notes.exe', 'extension'],
            'outside the allowlist' => ['notes/plan.md', 'allowlist'],
            'doubled slash' => ['docs//guide.md', null],
            'dot segment' => ['docs/./guide.md', null],
            'folder without a file' => ['docs/', 'allowlist'],
            'backslash separator' => ['docs\\guide.md', 'realpath'],
            'denied folder itself' => ['docs/storage', 'denylist'],
            'allowed markdown' => ['docs/guide.md', null],
            'allowed root file' => ['README.md', null],
            'double slash' => ['docs//x.md', null],
            'trailing slash on a file' => ['docs/guide.md/', 'realpath'],
            'directory path' => ['docs', 'allowlist'],
        ];
    }

    #[DataProvider('cases')]
    public function test_refuses_at_the_first_failing_step(string $path, ?string $step): void
    {
        $this->assertSame($step, (new DocsPathPolicy($this->root, self::ALLOWED))->refusalStep($path));
    }

    public function test_upper_case_folder_follows_the_file_system_case_rule(): void
    {
        $step = (new DocsPathPolicy($this->root, self::ALLOWED))->refusalStep('DOCS/guide.md');

        if (is_file($this->root . '/DOCS/guide.md')) {
            // Case-insensitive file system: the allowlist is compared exactly, so the spelling DOCS/ is refused (fail closed).
            $this->assertSame('allowlist', $step, 'case-insensitive file system: DOCS/ is not the allowlisted spelling');
        } else {
            $this->assertSame('realpath', $step, 'case-sensitive file system: the path does not exist');
        }
    }

    public function test_the_allowlist_is_matched_in_the_case_given(): void
    {
        $this->assertTrue(DocsPathPolicy::isAllowed('docs/guide.md', ['docs/']));
        $this->assertFalse(DocsPathPolicy::isAllowed('Docs/guide.md', ['docs/']));
        $this->assertFalse(DocsPathPolicy::isAllowed('docs/guide.md', ['Docs/']));
    }

    /** @return array<string, array{string, string, string}> allowlist entry, path, refusal step */
    public static function allowlistInsideDenied(): array
    {
        return [
            'file inside a denied folder' => ['docs/storage/file.md', 'docs/storage/file.md', 'denylist'],
            'dotfile listed as allowed' => ['docs/.hidden.md', 'docs/.hidden.md', 'denylist'],
        ];
    }

    #[DataProvider('allowlistInsideDenied')]
    public function test_an_allowlist_entry_inside_a_denied_segment_is_still_refused(string $entry, string $path, string $step): void
    {
        $this->assertSame($step, (new DocsPathPolicy($this->root, [$entry]))->refusalStep($path));
    }

    public function test_missing_file_is_refused_at_realpath(): void
    {
        $this->assertSame('realpath', (new DocsPathPolicy($this->root, self::ALLOWED))->refusalStep('docs/missing.md'));
    }
}
