<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Server\SourceTools;
use ContextGate\Settings\BuiltinClassification;
use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Unit\Source\DocsTree;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/** spec 009 US3: the package's source tables keep their classes; a host can hide them, nothing else (SC-004, SC-005). */
class BuiltinSourceTablesTest extends TestCase
{
    use GateCallHarness;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('source.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        $this->recordLines();
    }

    /** @param list<string> $names */
    private function enable(array $names): void
    {
        $entries = SourceTools::entries();
        $tools = [];

        foreach ($names as $name) {
            $tools[$name] = ['enabled' => true, 'gate' => 'source.allow'] + $entries[$name];
        }

        config(['context-gate.enabled' => true, 'context-gate.tools' => $tools, 'context-gate.callers.alice.tools' => $names]);
    }

    /** A host map with its own table and none of the built-in ones (an older file replaced the whole map). */
    private function hostOnly(): void
    {
        config(['context-gate.classification' => ['users' => ['id' => 'public', 'name' => 'personal']]]);
    }

    public function test_the_source_columns_stay_internal_when_the_host_lists_no_source_table(): void
    {
        $this->hostOnly();
        $this->enable(['routes.list', 'config.keys']);
        Route::get('api/orders', fn (): string => 'ok')->name('orders.list');
        config()->set('services.stripe.secret', 'FAKE_FIXTURE_ONLY');
        config()->set('context-gate.sources.config.env_map', ['services.stripe.secret' => 'STRIPE_SECRET']);

        $routes = $this->callTool('routes.list', []);
        $keys = $this->callTool('config.keys', []);

        $this->assertFalse($routes['isError']);
        $this->assertStringContainsString('"uri":"api\\/orders"', (string) $routes['content'][0]['text']);
        $this->assertStringContainsString('"key":"services.stripe.secret"', (string) $keys['content'][0]['text']);
    }

    public function test_hiding_source_docs_returns_no_column_and_the_receipt_lists_each_dropped_column(): void
    {
        $root = DocsTree::create();
        file_put_contents($root.'/docs/guide.md', "Order status guide.\n");
        $this->app?->setBasePath($root);
        config(['context-gate.sources.docs.paths' => ['docs/'], 'context-gate.classification' => ['source_docs' => false]]);
        $this->enable(['docs.read']);

        $result = $this->callTool('docs.read', ['path' => 'docs/guide.md']);
        DocsTree::remove($root);

        $this->assertStringNotContainsString('Order status guide', (string) $result['content'][0]['text']);
        $this->assertSame(3, substr_count(implode("\n", $this->lines), '"reason":"field.unlisted"'));
    }

    public function test_reclassifying_a_built_in_column_fails_gate_check_and_refuses_the_call(): void
    {
        config(['context-gate.classification' => ['source_routes' => ['uri' => 'public']]]);
        $this->enable(['routes.list']);

        $exit = Artisan::call('gate:check');
        $result = $this->callTool('routes.list', []);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('context-gate.classification.source_routes.uri', Artisan::output());
        $this->assertTrue($result['isError']);
        $this->assertSame('config.invalid', $this->lastReceipt()['reason']);
    }

    public function test_a_published_file_that_repeats_the_built_in_tables_passes(): void
    {
        config(['context-gate.classification' => BuiltinClassification::TABLES]);

        Artisan::call('gate:check');

        $this->assertStringNotContainsString('classification.source_', Artisan::output());
    }
}
