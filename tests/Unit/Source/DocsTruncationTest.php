<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Source\DocsSource;
use ContextGate\Source\SourceRequest;
use PHPUnit\Framework\TestCase;

class DocsTruncationTest extends TestCase
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

    public function test_file_over_the_per_call_limit_is_cut_at_a_line_boundary_and_marked_truncated(): void
    {
        $lines = '';
        for ($i = 1; $i <= 20; $i++) {
            $lines .= sprintf("line %02d\n", $i);
        }
        file_put_contents($this->root . '/docs/long.md', $lines);

        // Each line is 8 bytes; 37 bytes fit four whole lines (32 bytes), a fifth would end at byte 40.
        $result = (new DocsSource($this->root, ['docs/'], [], 37))
            ->read(new SourceRequest(['path' => 'docs/long.md']));

        $this->assertTrue($result->ok);
        $this->assertTrue($result->data['truncated']);
        $this->assertSame(
            ['untrusted' => true, 'source' => 'docs/long.md', 'text' => substr($lines, 0, 32)],
            $result->data['text'],
        );
    }
}
