<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\ReasonCode;
use ContextGate\Source\DocsSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\Unit\Source\DocsTree;
use PHPUnit\Framework\TestCase;

class DocsCanaryTest extends TestCase
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

    public function test_a_plain_canary_in_a_document_is_refused_without_content(): void
    {
        file_put_contents($this->root . '/docs/c.md', "token CANARY-PLAIN-123 here\n");

        $result = (new DocsSource($this->root, ['docs/'], ['CANARY-PLAIN-123'], 16384))
            ->read(new SourceRequest(['path' => 'docs/c.md']));

        $this->assertFalse($result->ok);
        $this->assertSame(ReasonCode::CanaryHit, $result->reason);
        $this->assertSame([], $result->data);
    }

    public function test_an_email_shaped_canary_is_caught_before_redaction_replaces_it(): void
    {
        file_put_contents($this->root . '/docs/c.md', "mail canary-abc123@example.invalid now\n");

        $result = (new DocsSource($this->root, ['docs/'], ['canary-abc123@example.invalid'], 16384))
            ->read(new SourceRequest(['path' => 'docs/c.md']));

        $this->assertFalse($result->ok);
        $this->assertSame(ReasonCode::CanaryHit, $result->reason);
        $this->assertSame([], $result->data);
    }
}
