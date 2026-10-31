<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use Closure;

/**
 * Stage 3 (R1): keeps only the projected fields, in their order. A field missing from a row is omitted.
 * Only scalar or null values pass: nested data never leaves through a projected field (FR-006).
 */
final class Projector implements Stage
{
    public function handle(Payload $payload, Closure $next): Payload
    {
        $payload->rows = array_map(function (array $row) use ($payload): array {
            $projected = [];

            foreach ($payload->fields as $field) {
                if (! array_key_exists($field, $row)) {
                    continue;
                }

                $value = $row[$field];

                if (is_array($value) || is_object($value)) {
                    $this->drop($payload, $field, 'field.not_scalar');

                    continue;
                }

                $projected[$field] = $value;
            }

            return $projected;
        }, $payload->rows);

        return $next($payload);
    }

    /** Records one drop per field and reason, however many rows carry it. */
    private function drop(Payload $payload, string $field, string $reason): void
    {
        foreach ($payload->dropped as $entry) {
            if ($entry['field'] === $field && $entry['reason'] === $reason) {
                return;
            }
        }

        $payload->dropped[] = ['field' => $field, 'reason' => $reason];
    }
}
