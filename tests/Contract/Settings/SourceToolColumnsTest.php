<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Tests\Fixtures\RoleFixture;
use ContextGate\Server\SourceTools;
use ContextGate\Tests\Contract\Runbook\RunbookToolHarness;
use ContextGate\Tests\Fixtures\Runbook\MixedRunbook;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * spec 009 T001 (characterization, SC-004 baseline): with the shipped config, each source tool returns its columns.
 * No test sets `classification`; a column that is not classified is dropped, so a returned column is one classified
 * internal by the shipped map.
 */
class SourceToolColumnsTest extends TestCase
{
    use RunbookToolHarness;

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

        config(['context-gate.enabled' => true, 'context-gate.tools' => $tools, ...RoleFixture::stdio('alice', $names)]);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return list<array<string, mixed>>
     */
    private function rowsOf(string $name, array $arguments = []): array
    {
        $result = $this->callTool($name, $arguments);
        $this->assertFalse($result['isError']);

        $rows = json_decode((string) $result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> */
        return is_array($rows) && array_is_list($rows) ? $rows : [$rows];
    }

    public function test_routes_list_returns_its_six_columns(): void
    {
        $this->enable(['routes.list']);
        Route::get('api/orders', fn (): string => 'ok')->name('orders.list');

        $rows = $this->rowsOf('routes.list');

        $this->assertSame(['method', 'uri', 'name', 'controller', 'rule_names', 'middleware'], array_keys($this->rowWith($rows, 'uri', 'api/orders')));
    }

    public function test_config_keys_returns_its_three_columns(): void
    {
        config()->set('services.stripe.secret', 'FAKE_FIXTURE_ONLY');
        config()->set('context-gate.sources.config.env_map', ['services.stripe.secret' => 'STRIPE_SECRET']);
        $this->enable(['config.keys']);

        $rows = $this->rowsOf('config.keys');

        $this->assertSame(['key', 'type', 'env'], array_keys($this->rowWith($rows, 'key', 'services.stripe.secret')));
    }

    public function test_schema_describe_returns_its_seven_columns(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
        });
        $this->enable(['schema.describe']);
        config(['context-gate.sources.eloquent.models' => [\App\Models\User::class]]);
        require_once dirname(__DIR__, 2).'/Fixtures/app/app/Models/User.php';

        $rows = $this->rowsOf('schema.describe', ['model' => \App\Models\User::class]);

        $this->assertSame(['model', 'table', 'kind', 'name', 'type', 'class', 'detail'], array_keys($rows[0]));
    }

    public function test_docs_read_returns_its_three_columns(): void
    {
        $app = $this->app;
        $this->assertNotNull($app);
        $app->setBasePath(dirname(__DIR__, 2).'/Fixtures/app');
        config(['context-gate.sources.docs.paths' => ['docs/']]);
        $this->enable(['docs.read']);

        $rows = $this->rowsOf('docs.read', ['path' => 'docs/guide.md']);

        $this->assertSame(['source', 'text', 'truncated'], array_keys($rows[0]));
    }

    public function test_openapi_endpoints_returns_its_eight_columns(): void
    {
        $app = $this->app;
        $this->assertNotNull($app);
        $app->setBasePath(dirname(__DIR__, 2).'/Fixtures');
        config(['context-gate.sources.openapi.paths' => ['openapi/']]);
        $this->enable(['openapi.endpoints']);

        $rows = $this->rowsOf('openapi.endpoints', ['path' => 'openapi/valid-3.1.json']);

        $this->assertSame(['version', 'method', 'path', 'parameters', 'request_schema', 'examples', 'response_schemas', 'security'], array_keys($rows[0]));
    }

    public function test_runbook_rows_returns_its_three_columns(): void
    {
        $this->configureRunbookTool(MixedRunbook::class);

        $rows = json_decode((string) $this->rawCall('runbook.mixed')['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['step', 'status', 'text'], array_keys($rows[0]));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function rowWith(array $rows, string $column, string $value): array
    {
        foreach ($rows as $row) {
            if (($row[$column] ?? null) === $value) {
                return $row;
            }
        }

        $this->fail("No row with {$column}={$value}.");
    }
}
