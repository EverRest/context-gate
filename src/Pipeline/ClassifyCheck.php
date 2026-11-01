<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use Closure;
use ContextGate\Classification\Catalog;
use ContextGate\Classification\Ceiling;
use ContextGate\Contract\Field;

/**
 * Stage 2 (R1, R2): drops fields with no catalog class, fields above the caller ceiling (except the forms that
 * never emit the raw value), fields whose form is not emitted yet, and fields whose form is not allowed for their
 * class. A relation is checked by its parent column's class. A row field the contract does not list is dropped as
 * `field.unlisted`, by name only, unless the caller turns the report off (a relation's loaded record is not returned
 * by the tool). Fail closed.
 */
final class ClassifyCheck implements Stage
{
    /** Forms the Transformer emits without the raw value. */
    private const NON_RAW = [Field::PSEUDONYM, Field::BUCKET, Field::DATE, Field::AGGREGATE];

    /** Forms the pipeline can emit today. */
    private const READY = [Field::PLAIN, Field::TEXT, Field::RELATION, Field::PSEUDONYM, Field::BUCKET, Field::DATE, Field::AGGREGATE];

    /** @param array<string, Field> $forms the contract's fields: name => form */
    public function __construct(
        private readonly Catalog $catalog,
        private readonly Ceiling $ceiling,
        private readonly string $table,
        private readonly array $forms,
        private readonly bool $reportUnlisted = true,
    ) {
    }

    public function handle(Payload $payload, Closure $next): Payload
    {
        $listed = $payload->fields;
        $kept = [];

        foreach ($payload->fields as $field) {
            $class = $this->catalog->classOf($this->table, $field);
            $form = $this->forms[$field] ?? null;
            $formName = $form?->form;

            // Pseudonym, bucket, date and aggregate never emit the raw value, so the ceiling does not apply to them.
            if ($class === null) {
                $payload->dropped[] = ['field' => $field, 'reason' => 'field.unclassified'];
            } elseif (! in_array($formName, self::NON_RAW, true) && ! Ceiling::allows($class, $this->ceiling)) {
                $payload->dropped[] = ['field' => $field, 'reason' => 'ceiling.exceeded'];
            } elseif ($form === null || ! in_array($formName, self::READY, true)) {
                $payload->dropped[] = ['field' => $field, 'reason' => 'field.form_not_ready'];
            } elseif (! $form->allows($class)) {
                $payload->dropped[] = ['field' => $field, 'reason' => 'field.form_not_allowed'];
            } else {
                $kept[] = $field;
            }
        }

        $payload->fields = $kept;

        if ($this->reportUnlisted) {
            foreach ($this->unlisted($payload->rows, $listed) as $field) {
                $payload->dropped[] = ['field' => $field, 'reason' => 'field.unlisted'];
            }
        }

        return $next($payload);
    }

    /**
     * The column names the rows carry that the contract does not list, each once, in first-seen order.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $listed the contract's fields
     * @return list<string>
     */
    private function unlisted(array $rows, array $listed): array
    {
        $names = [];

        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                if (! in_array((string) $column, $listed, true)) {
                    $names[(string) $column] = true;
                }
            }
        }

        return array_keys($names);
    }
}
