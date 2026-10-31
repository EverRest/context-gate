<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Source\DocsSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\Unit\Source\DocsTree;
use PHPUnit\Framework\TestCase;

class DocsRedactionTest extends TestCase
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

    public function test_seeded_email_in_a_document_is_replaced_by_its_placeholder(): void
    {
        file_put_contents($this->root . '/docs/contact.md', "Reach Ada at ada.lovelace@example.com for help.\n");

        $result = (new DocsSource($this->root, ['docs/'], [], 16384))
            ->read(new SourceRequest(['path' => 'docs/contact.md']));

        $this->assertTrue($result->ok);
        $this->assertSame("Reach Ada at [EMAIL] for help.\n", $result->data['text']);
        $this->assertStringNotContainsString('ada.lovelace@example.com', (string) json_encode($result->data));
    }
}
