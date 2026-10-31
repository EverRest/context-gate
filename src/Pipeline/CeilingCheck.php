<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use Closure;
use ContextGate\Classification\Catalog;
use ContextGate\Classification\Ceiling;
use ContextGate\Contract\Field;

/**
 * R12 (FR-007): drops a field whose class is above the caller ceiling, and a field with no class at all.
 * A pseudonym, bucket or aggregate never emits the raw value, so the ceiling does not drop it: a personal
 * field of a related record is shown as a pseudonym under an internal ceiling (User Story 4, scenario 3).
 */
final class CeilingCheck implements Stage
{
    /** @param array<string, Field> $forms the contract's fields: name => form */
    public function __construct(
        private readonly Catalog $catalog,
        private readonly Ceiling $ceiling,
        private readonly string $table,
        private readonly array $forms,
    ) {
    }

    public function handle(Payload $payload, Closure $next): Payload
    {
        $kept = [];

        foreach ($payload->fields as $field) {
            $class = $this->catalog->classOf($this->table, $field);

            if ($class === null) {
                $payload->dropped[] = ['field' => $field, 'reason' => 'field.unclassified'];
            } elseif (! Ceiling::allows($class, $this->ceiling) && $this->emitsRaw($field)) {
                $payload->dropped[] = ['field' => $field, 'reason' => 'ceiling.exceeded'];
            } else {
                $kept[] = $field;
            }
        }

        $payload->fields = $kept;

        return $next($payload);
    }

    private function emitsRaw(string $field): bool
    {
        $form = isset($this->forms[$field]) ? $this->forms[$field]->form : null;

        return ! in_array($form, [Field::PSEUDONYM, Field::BUCKET, Field::AGGREGATE], true);
    }
}
