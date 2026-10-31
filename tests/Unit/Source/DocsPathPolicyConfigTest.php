<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Source\DocsPathPolicy;
use ContextGate\Tests\TestCase;

class DocsPathPolicyConfigTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = DocsTree::create();
    }

    protected function tearDown(): void
    {
        DocsTree::remove($this->root);
        parent::tearDown();
    }

    public function test_config_cannot_make_a_denylisted_env_file_readable(): void
    {
        config()->set('context-gate.sources.docs.paths', ['.env', 'docs/', 'README.md']);

        /** @var list<string> $allowed */
        $allowed = config('context-gate.sources.docs.paths');
        $policy = new DocsPathPolicy($this->root, $allowed);

        $this->assertSame('denylist', $policy->refusalStep('.env'));
        $this->assertSame('denylist', $policy->refusalStep('docs/.env'));
    }

    public function test_config_cannot_make_a_storage_path_readable(): void
    {
        config()->set('context-gate.sources.docs.paths', ['storage/', 'docs/']);

        /** @var list<string> $allowed */
        $allowed = config('context-gate.sources.docs.paths');
        $policy = new DocsPathPolicy($this->root, $allowed);

        $this->assertSame('denylist', $policy->refusalStep('docs/storage/file.md'));
        $this->assertNull($policy->refusalStep('docs/guide.md'));
    }

    public function test_denylist_is_a_code_constant(): void
    {
        $this->assertContains('.env*', DocsPathPolicy::DENYLIST);
        $this->assertContains('storage/', DocsPathPolicy::DENYLIST);
        $this->assertContains('vendor/', DocsPathPolicy::DENYLIST);
    }
}
