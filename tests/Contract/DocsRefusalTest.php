<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\ReasonCode;
use ContextGate\Source\DocsSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\Unit\Source\DocsTree;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DocsRefusalTest extends TestCase
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

    /** @return array<string, array{string, string}> path => refusal step */
    public static function refusals(): array
    {
        return [
            'symlink to the env file' => ['docs/link-to-env', 'denylist'],
            'symlink escaping the root' => ['docs/link-outside', 'root'],
            'dotenv inside docs' => ['docs/.env', 'denylist'],
            'key file' => ['docs/keys/server.key', 'denylist'],
            'non-text extension' => ['docs/notes.exe', 'extension'],
            'outside the allowlist' => ['notes/plan.md', 'allowlist'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_refused_request_returns_no_data_and_the_step_name(string $path, string $step): void
    {
        $result = (new DocsSource($this->root, ['docs/', 'README.md'], [], 16384))
            ->read(new SourceRequest(['path' => $path]));

        $this->assertFalse($result->ok);
        $this->assertSame(ReasonCode::SourceRefused, $result->reason);
        $this->assertSame('source.refused', $result->reason->value);
        $this->assertSame($step, $result->refusalStep);
        $this->assertSame([], $result->data);
    }

    public function test_configuration_listing_the_env_file_still_refuses_it(): void
    {
        $result = (new DocsSource($this->root, ['.env', 'docs/'], [], 16384))
            ->read(new SourceRequest(['path' => '.env']));

        $this->assertFalse($result->ok);
        $this->assertSame('denylist', $result->refusalStep);
        $this->assertSame([], $result->data);
    }
}
