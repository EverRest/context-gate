<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use Closure;
use ContextGate\Privacy\Redactor;
use Throwable;

/**
 * Stage 7 (R7): redacts each text field with the regular-expression layer. A field that raises an exception is
 * dropped from every row and recorded as redaction.failed; the rest of the response is returned (FR-014).
 */
final class Redact implements Stage
{
    /** @param list<string> $textFields */
    public function __construct(
        private readonly Redactor $redactor,
        private readonly array $textFields,
    ) {
    }

    public function handle(Payload $payload, Closure $next): Payload
    {
        foreach ($this->textFields as $field) {
            if (! in_array($field, $payload->fields, true)) {
                continue;
            }

            try {
                $payload->rows = array_map(function (array $row) use ($field): array {
                    if (isset($row[$field]) && is_string($row[$field])) {
                        $row[$field] = $this->redactor->redact($row[$field]);
                    }

                    return $row;
                }, $payload->rows);
            } catch (Throwable) {
                $payload->rows = array_map(function (array $row) use ($field): array {
                    unset($row[$field]);

                    return $row;
                }, $payload->rows);
                $payload->fields = array_values(array_diff($payload->fields, [$field]));
                $payload->dropped[] = ['field' => $field, 'reason' => 'redaction.failed'];
            }
        }

        return $next($payload);
    }
}
