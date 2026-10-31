<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Server\ReasonCode;
use ContextGate\Source\DocsSource;
use ContextGate\Source\SourceRequest;
use PHPUnit\Framework\TestCase;

class DocsMissingFileTest extends TestCase
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

    public function test_file_deleted_between_the_policy_check_and_the_read_returns_source_missing(): void
    {
        $path = $this->root . '/docs/gone.md';
        file_put_contents($path, "# Gone\n");

        $source = new DocsSource($this->root, ['docs/'], [], 16384, static function () use ($path): void {
            unlink($path);
        });
        $result = $source->read(new SourceRequest(['path' => 'docs/gone.md']));

        $this->assertFalse($result->ok);
        $this->assertSame(ReasonCode::SourceMissing, $result->reason);
        $this->assertSame([], $result->data);
    }
}
