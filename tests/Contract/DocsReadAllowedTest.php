<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Source\DocsSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\Unit\Source\DocsTree;
use PHPUnit\Framework\TestCase;

class DocsReadAllowedTest extends TestCase
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

    public function test_markdown_under_an_allowlisted_path_is_read_as_plain_text(): void
    {
        $result = (new DocsSource($this->root, ['docs/', 'README.md', 'CHANGELOG.md'], [], 16384))
            ->read(new SourceRequest(['path' => 'docs/guide.md']));

        $this->assertTrue($result->ok);
        $this->assertSame('docs/guide.md', $result->data['source']);
        $this->assertSame("Order status guide.\n", $result->data['text']);
        $this->assertFalse($result->data['truncated']);
    }
}
