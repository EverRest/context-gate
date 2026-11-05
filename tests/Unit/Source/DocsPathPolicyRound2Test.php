<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Source\DocsPathPolicy;
use ContextGate\Source\DocsSource;
use ContextGate\Source\SourceRequest;
use PHPUnit\Framework\TestCase;

class DocsPathPolicyRound2Test extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = DocsTree::create();
    }

    protected function tearDown(): void
    {
        DocsTree::remove($this->root);
    }

    /** @param list<string> $allowed */
    private function step(string $path, array $allowed = ['docs/']): ?string
    {
        return (new DocsPathPolicy($this->root, $allowed))->refusalStep($path);
    }

    public function test_an_entry_without_a_trailing_slash_does_not_admit_a_sibling_folder(): void
    {
        mkdir($this->root . '/docs-private');
        file_put_contents($this->root . '/docs-private/x.md', "# P\n");

        $this->assertSame('allowlist', $this->step('docs-private/x.md', ['docs']));
    }

    public function test_a_nul_byte_is_refused_at_realpath(): void
    {
        $this->assertSame('realpath', $this->step("docs/guide.md\0.txt"));
    }

    public function test_a_sibling_folder_sharing_the_root_prefix_is_refused_at_root(): void
    {
        $sibling = $this->root . '-sib';
        mkdir($sibling . '/docs', 0777, true);
        file_put_contents($sibling . '/docs/x.md', "# S\n");

        try {
            $this->assertSame('root', $this->step('../' . basename($sibling) . '/docs/x.md'));
        } finally {
            unlink($sibling . '/docs/x.md');
            rmdir($sibling . '/docs');
            rmdir($sibling);
        }
    }

    public function test_denylist_matches_case_variants(): void
    {
        is_dir($this->root . '/docs/Storage') || mkdir($this->root . '/docs/Storage', 0777, true);
        file_put_contents($this->root . '/docs/Storage/a.md', "# S\n");
        file_put_contents($this->root . '/docs/Auth.JSON', "{}\n");

        $this->assertSame('denylist', $this->step('docs/Storage/a.md'));
        $this->assertSame('denylist', $this->step('docs/Auth.JSON'));
    }

    public function test_unicode_case_folding_does_not_bypass_the_denylist(): void
    {
        $this->assertTrue(DocsPathPolicy::isDeniedSegment("\u{017F}torage"));
        $this->assertTrue(DocsPathPolicy::isDeniedSegment('ｓｔｏｒａｇｅ'));
        $this->assertTrue(DocsPathPolicy::isDeniedSegment('ｎｏｄｅ_ｍｏｄｕｌｅｓ'));
        $this->assertTrue(DocsPathPolicy::isDeniedSegment("auth.j\u{017F}on"));
        $this->assertTrue(DocsPathPolicy::isDeniedSegment("bad\xC3\x28name"));
        $this->assertFalse(DocsPathPolicy::isDeniedSegment('guide.md'));
    }

    public function test_docs_source_refuses_a_file_swapped_for_an_outside_symlink_after_the_policy(): void
    {
        $swap = function (): void {
            unlink($this->root . '/docs/guide.md');
            symlink($this->root . '/../outside.md', $this->root . '/docs/guide.md');
        };

        $result = (new DocsSource($this->root, ['docs/'], [], 16384, $swap))
            ->read(new SourceRequest(['path' => 'docs/guide.md']));

        $this->assertFalse($result->ok);
        $this->assertSame('root', $result->refusalStep);
    }

    public function test_docs_source_refuses_a_file_swapped_for_a_denylisted_file_inside_the_root(): void
    {
        $swap = function (): void {
            unlink($this->root . '/docs/guide.md');
            symlink($this->root . '/.env', $this->root . '/docs/guide.md');
        };

        $result = (new DocsSource($this->root, ['docs/'], [], 16384, $swap))
            ->read(new SourceRequest(['path' => 'docs/guide.md']));

        $this->assertFalse($result->ok);
        $this->assertSame('denylist', $result->refusalStep);
    }

    public function test_docs_source_refuses_a_file_swapped_for_one_outside_the_allowlist(): void
    {
        $swap = function (): void {
            unlink($this->root . '/docs/guide.md');
            symlink($this->root . '/notes/plan.md', $this->root . '/docs/guide.md');
        };

        $result = (new DocsSource($this->root, ['docs/'], [], 16384, $swap))
            ->read(new SourceRequest(['path' => 'docs/guide.md']));

        $this->assertFalse($result->ok);
        $this->assertSame('allowlist', $result->refusalStep);
    }
}
