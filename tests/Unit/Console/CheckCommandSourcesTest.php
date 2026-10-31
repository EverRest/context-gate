<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Server\SourceTools;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;

/** F6: with a source tool enabled, gate:check fails on a bad source setting, naming the key. */
class CheckCommandSourcesTest extends TestCase
{
    /** @param list<string> $names the source tools to enable */
    private function enable(array $names): void
    {
        $entries = SourceTools::entries();
        $tools = [];

        foreach ($names as $name) {
            $tools[$name] = ['enabled' => true, 'gate' => 'source.allow'] + $entries[$name];
        }

        config(['context-gate.tools' => $tools]);
    }

    private function check(): PendingCommand
    {
        $command = $this->artisan('gate:check');
        if (! $command instanceof PendingCommand) {
            $this->fail('artisan() did not return a PendingCommand.');
        }

        return $command;
    }

    public function test_a_valid_source_configuration_exits_0(): void
    {
        $this->enable(['routes.list', 'schema.describe', 'config.keys', 'docs.read']);
        config(['context-gate.sources.docs.paths' => ['docs/', 'README.md']]);
        config(['context-gate.sources.eloquent.models' => [Model::class]]);

        $this->check()->assertExitCode(0)->execute();
    }

    /** @return array<string, array{string}> */
    public static function badDocsPaths(): array
    {
        return [
            'empty' => [''],
            'leading slash' => ['/etc'],
            'parent segment' => ['docs/../.env'],
            'glob character' => ['docs/*.md'],
        ];
    }

    #[DataProvider('badDocsPaths')]
    public function test_an_invalid_docs_path_exits_1_and_names_the_key(string $path): void
    {
        $this->enable(['docs.read']);
        config(['context-gate.sources.docs.paths' => [$path]]);

        $this->check()->assertExitCode(1)->expectsOutputToContain('sources.docs.paths[0]')->execute();
    }

    public function test_a_model_class_that_does_not_exist_exits_1_and_names_the_key(): void
    {
        $this->enable(['schema.describe']);
        config(['context-gate.sources.eloquent.models' => ['App\\Models\\NoSuchModel']]);

        $this->check()->assertExitCode(1)->expectsOutputToContain('sources.eloquent.models[0]')->execute();
    }

    public function test_a_non_string_env_map_value_exits_1_and_names_the_key(): void
    {
        $this->enable(['config.keys']);
        config(['context-gate.sources.config.env_map' => ['app.name' => 42]]);

        $this->check()->assertExitCode(1)->expectsOutputToContain('sources.config.env_map.app.name')->execute();
    }

    /** @return array<string, array{string, string}> source tool, its table */
    public static function sourceTables(): array
    {
        return [
            'routes' => ['routes.list', 'source_routes'],
            'config keys' => ['config.keys', 'source_config_keys'],
            'schema items' => ['schema.describe', 'source_schema_items'],
            'docs' => ['docs.read', 'source_docs'],
        ];
    }

    #[DataProvider('sourceTables')]
    public function test_a_source_table_missing_from_the_classification_exits_1_and_names_it(string $tool, string $table): void
    {
        $this->enable([$tool]);
        $map = (array) config('context-gate.classification');
        unset($map[$table]);
        config(['context-gate.classification' => $map]);

        $this->check()->assertExitCode(1)->expectsOutputToContain("classification.{$table}")->execute();
    }

    public function test_a_disabled_source_tool_is_not_checked(): void
    {
        $entries = SourceTools::entries();
        config(['context-gate.tools' => ['docs.read' => $entries['docs.read']]]);
        config(['context-gate.sources.docs.paths' => ['/etc']]);

        $this->check()->assertExitCode(0)->execute();
    }
}
