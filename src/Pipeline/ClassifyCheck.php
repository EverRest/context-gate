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
 * `field.unlisted`, by name when the name is an identifier and otherwise as '*', unless the caller turns the report off (a relation's loaded record is not returned
 * by the tool). Fail closed.
 *
 * @internal May change without notice.
 */
final class ClassifyCheck implements Stage
{
    /** Forms the Transformer emits without the raw value. */
    private const NON_RAW = [Field::PSEUDONYM, Field::BUCKET, Field::DATE, Field::AGGREGATE];

    /** Forms the pipeline can emit today. */
    private const READY = [Field::PLAIN, Field::TEXT, Field::RELATION, Field::PSEUDONYM, Field::BUCKET, Field::DATE, Field::AGGREGATE];

    /** The keys that may be recorded by name in a receipt; anything else is recorded as the placeholder '*'. */
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]{0,63}\z/';

    /** Names recorded per call for unlisted fields. */
    private const NAME_LIMIT = 50;

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
     * The column names the rows carry that the contract does not list, each once, in first-seen order. A key is
     * recorded by name only when it is an identifier, at most NAME_LIMIT names per call; any other key, and every
     * name past the limit, is counted once as the placeholder '*', because a handler may key a row by a value.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $listed the contract's fields
     * @return list<string>
     */
    private function unlisted(array $rows, array $listed): array
    {
        $names = [];
        $placeholder = false;

        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                $name = (string) $column;

                if (in_array($name, $listed, true) || isset($names[$name])) {
                    continue;
                }

                if (preg_match(self::IDENTIFIER, $name) === 1 && count($names) < self::NAME_LIMIT) {
                    $names[$name] = true;
                } else {
                    $placeholder = true;
                }
            }
        }

        $result = array_keys($names);

        if ($placeholder) {
            $result[] = '*';
        }

        return $result;
    }
}
