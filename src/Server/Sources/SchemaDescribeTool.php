<?php

declare(strict_types=1);

namespace ContextGate\Server\Sources;

use ContextGate\Classification\Catalog;
use ContextGate\Server\SourceTools;
use ContextGate\Server\Tool;
use ContextGate\Source\SchemaSource;
use ContextGate\Source\SourceFailure;
use ContextGate\Source\SourceRequest;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Response;

/** schema.describe (spec 003 US2): one row per column, index, cast, relation, and one row for the hidden count. */
final class SchemaDescribeTool extends Tool
{
    public function name(): string
    {
        return 'schema.describe';
    }

    public function description(): string
    {
        return 'Describes the table of a configured model: columns with their class, indexes, casts and relations. No row is read.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['model' => ['type' => 'string']],
            'required' => ['model'],
        ];
    }

    public function handle(array $arguments): Response
    {
        /** @var array<string, array<string, mixed>> $map */
        $map = config('context-gate.classification', []);
        $models = [];

        foreach ((array) config('context-gate.sources.eloquent.models', []) as $model) {
            if (is_string($model) && is_a($model, Model::class, true)) {
                $models[] = $model;
            }
        }

        $result = (new SchemaSource(new Catalog($map), $models))->read(new SourceRequest($arguments));

        if (! $result->ok) {
            throw SourceFailure::from($result);
        }

        $data = $result->data;
        $model = SourceTools::text($data['model'] ?? null);
        $table = SourceTools::text($data['table'] ?? null);
        $rows = [];

        $item = static fn (string $kind, string $name, string $type, string $class, string $detail): array => [
            'model' => $model,
            'table' => $table,
            'kind' => $kind,
            'name' => $name,
            'type' => $type,
            'class' => $class,
            'detail' => $detail,
        ];

        foreach (SourceTools::items($data['columns'] ?? null) as $column) {
            $rows[] = $item('column', SourceTools::text($column['name'] ?? null), SourceTools::text($column['type'] ?? null), SourceTools::text($column['class'] ?? null), '');
        }

        foreach (SourceTools::items($data['indexes'] ?? null) as $index) {
            $rows[] = $item('index', SourceTools::text($index['name'] ?? null), ($index['unique'] ?? false) === true ? 'unique' : 'index', '', SourceTools::joined($index['columns'] ?? null));
        }

        foreach (is_array($data['casts'] ?? null) ? $data['casts'] : [] as $column => $cast) {
            $rows[] = $item('cast', SourceTools::text($column), SourceTools::text($cast), '', '');
        }

        foreach (SourceTools::items($data['relations'] ?? null) as $relation) {
            $rows[] = $item('relation', SourceTools::text($relation['name'] ?? null), SourceTools::text($relation['type'] ?? null), '', SourceTools::text($relation['model'] ?? null));
        }

        $rows[] = $item('hidden_columns', 'hidden_columns', '', '', SourceTools::text($data['hidden_columns'] ?? null));

        return Response::text((string) json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
