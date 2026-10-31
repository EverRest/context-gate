<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use Closure;

/**
 * Stage 3 (R1): keeps only the projected fields, in their order. A field missing from a row is omitted.
 */
final class Projector implements Stage
{
    public function handle(Payload $payload, Closure $next): Payload
    {
        $payload->rows = array_map(function (array $row) use ($payload): array {
            $projected = [];

            foreach ($payload->fields as $field) {
                if (array_key_exists($field, $row)) {
                    $projected[$field] = $row[$field];
                }
            }

            return $projected;
        }, $payload->rows);

        return $next($payload);
    }
}
