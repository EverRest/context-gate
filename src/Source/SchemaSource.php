<?php

declare(strict_types=1);

namespace ContextGate\Source;

use ContextGate\Classification\Catalog;
use ContextGate\Classification\Ceiling;
use ContextGate\Server\ReasonCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Describes a configured model's table without reading any row (research R3, FR-005, FR-006). A column without a
 * catalog class is dropped, as the config default says; a `secret` column is dropped and counted in hidden_columns.
 * Indexes that touch a dropped column are dropped too, so no secret column name leaks through them.
 */
final class SchemaSource implements Source
{
    /** @param list<class-string<Model>> $models the configured model list (sources.eloquent.models) */
    public function __construct(
        private readonly Catalog $catalog,
        private readonly array $models,
    ) {
    }

    public function name(): string
    {
        return 'schema.describe';
    }

    public function read(SourceRequest $request): SourceResult
    {
        $model = $request->arguments['model'] ?? null;

        if (! is_string($model) || ! in_array($model, $this->models, true)) {
            return SourceResult::failed(ReasonCode::PolicyDenied);
        }

        /** @var Model $instance */
        $instance = new $model();
        $table = $instance->getTable();

        try {
            if (! Schema::hasTable($table)) {
                return SourceResult::failed(ReasonCode::SchemaMissing);
            }

            $rawColumns = Schema::getColumns($table);
            $rawIndexes = Schema::getIndexes($table);
        } catch (QueryException) {
            return SourceResult::failed(ReasonCode::SchemaMissing);
        }

        $columns = [];
        $allowed = [];
        $hidden = 0;

        foreach ($rawColumns as $column) {
            $name = (string) $column['name'];
            $class = $this->catalog->classOf($table, $name);

            if ($class === null) {
                continue;
            }

            if ($class === Ceiling::Secret) {
                $hidden++;

                continue;
            }

            $allowed[$name] = true;
            $columns[] = ['name' => $name, 'type' => (string) ($column['type_name'] ?? $column['type']), 'class' => $class->value];
        }

        $indexes = [];

        foreach ($rawIndexes as $index) {
            $names = array_map(static fn (mixed $name): string => (string) $name, (array) $index['columns']);

            if (array_diff($names, array_keys($allowed)) !== []) {
                continue;
            }

            $indexes[] = ['name' => (string) $index['name'], 'columns' => $names, 'unique' => (bool) $index['unique']];
        }

        $casts = [];

        foreach ($instance->getCasts() as $column => $cast) {
            if (isset($allowed[$column])) {
                $casts[$column] = $cast;
            }
        }

        return SourceResult::ok([
            'model' => $model,
            'table' => $table,
            'columns' => $columns,
            'hidden_columns' => $hidden,
            'indexes' => $indexes,
            'casts' => $casts,
            'relations' => $this->relations($instance),
        ]);
    }

    /** @return list<array{name: string, model: string, type: string}> */
    private function relations(Model $instance): array
    {
        $relations = [];

        foreach ((new ReflectionClass($instance))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $returns = $method->getReturnType();

            if ($method->getNumberOfRequiredParameters() > 0
                || ! $returns instanceof ReflectionNamedType
                || ! is_a($returns->getName(), Relation::class, true)) {
                continue;
            }

            // A relation method is user code: one that throws is skipped, not allowed to fail the whole call.
            try {
                $relation = $method->invoke($instance);
            } catch (Throwable) {
                continue;
            }

            if (! $relation instanceof Relation) {
                continue;
            }

            $related = $relation->getRelated()::class;

            $relations[] = [
                'name' => $method->getName(),
                // A model that is not on the configured list is not named.
                'model' => in_array($related, $this->models, true) ? $related : 'hidden',
                'type' => class_basename($relation),
            ];
        }

        return $relations;
    }
}
