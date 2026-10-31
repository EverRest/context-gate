<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use Closure;
use ContextGate\Classification\Catalog;
use ContextGate\Classification\Ceiling;
use ContextGate\Contract\Field;

/**
 * Stage 2 (R1): drops fields with no catalog class, fields above the caller ceiling, and fields whose
 * form is not emitted yet or is not allowed for their class. Fail closed.
 */
final class ClassifyCheck implements Stage
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
            $form = $this->forms[$field] ?? null;

            if ($class === null) {
                $payload->dropped[] = ['field' => $field, 'reason' => 'field.unclassified'];
            } elseif (! Ceiling::allows($class, $this->ceiling)) {
                $payload->dropped[] = ['field' => $field, 'reason' => 'ceiling.exceeded'];
            } elseif ($form === null || ! in_array($form->form, [Field::PLAIN, Field::TEXT], true)) {
                // Transforms (pseudonym, bucket, date, aggregate) are not built yet: fail closed (002-I3 and later).
                $payload->dropped[] = ['field' => $field, 'reason' => 'field.form_not_ready'];
            } elseif (! $form->allows($class)) {
                $payload->dropped[] = ['field' => $field, 'reason' => 'field.form_not_allowed'];
            } else {
                $kept[] = $field;
            }
        }

        $payload->fields = $kept;

        return $next($payload);
    }
}
