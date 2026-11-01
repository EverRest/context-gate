<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use Closure;
use ContextGate\Classification\Catalog;
use ContextGate\Classification\Ceiling;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;
use ContextGate\Contracts\IssuesPseudonyms;
use ContextGate\Server\Failure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Throwable;

/**
 * Stage 4 (R1, FR-011, FR-012): replaces each relation's foreign key with the related record, shaped by the
 * related contract. One query per relation. The related rows go through the same ceiling, transform and
 * projection as the parent, so a related field is never raw above the caller ceiling.
 * Depth is one: ContractValidator refuses a related contract that has a relation.
 * ponytail: the nested object lives under the foreign key's name and no row limit exists yet; the byte budget
 * counts nested rows, since they are part of the parent row.
 *
 * @internal May change without notice.
 */
final class Relations implements Stage
{
    /** @param array<string, Field> $forms the parent contract's fields: column => form */
    public function __construct(
        private readonly array $forms,
        private readonly Catalog $catalog,
        private readonly Ceiling $ceiling,
        private readonly IssuesPseudonyms $pseudonymizer,
        private readonly string $scope,
        private readonly ?string $caller = null,
    ) {
    }

    public function handle(Payload $payload, Closure $next): Payload
    {
        foreach ($this->forms as $column => $field) {
            if ($field->form !== Field::RELATION) {
                continue;
            }

            // A failed relation is dropped with its foreign key; the rest of the response is returned.
            try {
                $this->load($payload, $column, $field);
            } catch (Throwable $e) {
                Failure::hold($e);
                $payload->rows = array_map(static function (array $row) use ($column): array {
                    unset($row[$column]);

                    return $row;
                }, $payload->rows);
                $payload->fields = array_values(array_diff($payload->fields, [$column]));
                $payload->dropped[] = ['field' => $column, 'reason' => 'relation.failed'];
            }
        }

        return $next($payload);
    }

    private function load(Payload $payload, string $column, Field $field): void
    {
        /** @var class-string<ContextContract> $contractClass */
        $contractClass = $field->options['contract'];
        $related = app($contractClass);
        $relatedForms = $related->fields();

        $modelClass = $related->model();
        /** @var Model $model */
        $model = new $modelClass();
        $key = $model->getKeyName();
        $table = $model->getTable();

        $ids = array_values(array_unique(array_filter(
            array_column($payload->rows, $column),
            static fn (mixed $id): bool => is_int($id) || is_string($id),
        )));

        $records = $ids === [] ? [] : $this->records($model, $key, $ids);

        $identity = static fn (Payload $next): Payload => $next;

        // The related fields get the same classification and form rules as the parent (ClassifyCheck).
        $loaded = (new ClassifyCheck($this->catalog, $this->ceiling, $table, $relatedForms, reportUnlisted: false))->handle(
            new Payload($records, array_keys($relatedForms)),
            $identity,
        );
        // Transform before projection: a pseudonym needs the record key, which the projection may drop.
        $loaded = (new Transformer($this->pseudonymizer, $table, $this->scope, $relatedForms, $key, $this->caller))->handle($loaded, $identity);
        $loaded = (new Projector())->handle($loaded, $identity);

        // The projected rows keep the order of the loaded records, so they pair with them by position.
        $byKey = array_combine(
            array_map(static fn (array $record): string => (string) $record[$key], $records),
            $loaded->rows,
        );

        foreach ($payload->rows as $index => $row) {
            if (array_key_exists($column, $row)) {
                $id = $row[$column];
                $payload->rows[$index][$column] = is_int($id) || is_string($id) ? ($byKey[(string) $id] ?? null) : null;
            }
        }

        foreach ($loaded->dropped as $entry) {
            $payload->dropped[] = ['field' => $column.'.'.$entry['field'], 'reason' => $entry['reason']];
        }
    }

    /**
     * Raw rows from the model's table: one query, no Eloquent casts or global scopes.
     *
     * @param list<int|string> $ids
     * @return list<array<string, mixed>>
     */
    private function records(Model $model, string $key, array $ids): array
    {
        $records = [];

        $query = $model->getConnection()->table($model->getTable())->whereIn($key, $ids);

        // R3: soft-deleted rows are not part of the relation (the base-table query has no global scopes).
        if (in_array(SoftDeletes::class, class_uses_recursive($model), true) && method_exists($model, 'getDeletedAtColumn')) {
            $query->whereNull($model->getDeletedAtColumn());
        }

        foreach ($query->get() as $record) {
            $records[] = (array) $record;
        }

        return $records;
    }
}
