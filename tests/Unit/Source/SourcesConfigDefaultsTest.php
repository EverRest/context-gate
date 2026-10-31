<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Classification\Catalog;
use ContextGate\Source\DocsSource;
use ContextGate\Source\RoutesSource;
use ContextGate\Source\SchemaSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\Fixtures\CustomerRecord;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/** The four sources keys default to empty, and a configured value is the one the source uses. */
class SourcesConfigDefaultsTest extends TestCase
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

    public function test_the_four_source_keys_default_to_empty_values(): void
    {
        $this->assertSame([], config('context-gate.sources.routes.exclude'));
        $this->assertSame([], config('context-gate.sources.eloquent.models'));
        $this->assertSame([], config('context-gate.sources.config.env_map'));
        $this->assertSame([], config('context-gate.sources.docs.paths'));
    }

    public function test_routes_list_works_with_the_empty_default(): void
    {
        Route::get('api/plain', fn (): string => 'ok');

        $result = (new RoutesSource())->read(new SourceRequest([]));

        $this->assertTrue($result->ok);
        $this->assertNotEmpty($result->data['routes']);
    }

    public function test_schema_refuses_every_model_with_the_empty_default(): void
    {
        $result = (new SchemaSource(new Catalog([]), $this->configuredModels()))
            ->read(new SourceRequest(['model' => CustomerRecord::class]));

        $this->assertFalse($result->ok);
        $this->assertSame('policy.denied', $result->reason?->value);
    }

    public function test_schema_reads_a_configured_model_list(): void
    {
        config()->set('context-gate.sources.eloquent.models', [CustomerRecord::class]);

        $result = (new SchemaSource(new Catalog([]), $this->configuredModels()))
            ->read(new SourceRequest(['model' => CustomerRecord::class]));

        $this->assertNotSame('policy.denied', $result->reason?->value);
    }

    public function test_docs_refuses_every_path_with_the_empty_default(): void
    {
        $result = (new DocsSource($this->root, $this->configuredPaths(), [], 16384))
            ->read(new SourceRequest(['path' => 'docs/guide.md']));

        $this->assertFalse($result->ok);
        $this->assertSame('allowlist', $result->refusalStep);
    }

    public function test_docs_reads_a_configured_path(): void
    {
        config()->set('context-gate.sources.docs.paths', ['docs/']);

        $result = (new DocsSource($this->root, $this->configuredPaths(), [], 16384))
            ->read(new SourceRequest(['path' => 'docs/guide.md']));

        $this->assertTrue($result->ok);
    }

    /** @return list<class-string<Model>> */
    private function configuredModels(): array
    {
        $models = [];

        foreach ((array) config('context-gate.sources.eloquent.models', []) as $model) {
            if (is_string($model) && is_a($model, Model::class, true)) {
                $models[] = $model;
            }
        }

        return $models;
    }

    /** @return list<string> */
    private function configuredPaths(): array
    {
        $paths = [];

        foreach ((array) config('context-gate.sources.docs.paths', []) as $path) {
            if (is_string($path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }
}
