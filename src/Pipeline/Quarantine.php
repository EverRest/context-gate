<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use Closure;

/**
 * Stage 9: wraps each text field as untrusted content with its source name. Injection phrases set the
 * injection.phrase flag. The stage never blocks: a flag is a signal, not a control.
 *
 * Only fields declared Field::text() are wrapped and scanned. User-written columns MUST use Field::text(); a plain
 * field reaches the model unwrapped.
 *
 * @internal May change without notice.
 */
final class Quarantine implements Stage
{
    private const FLAG = 'injection.phrase';

    private const PHRASES = '/(?:ignore|disregard|forget|override)\s+(?:all\s+|any\s+|the\s+|your\s+|my\s+)*'
        . '(?:previous|prior|above|earlier|preceding|system)?\s*(?:instructions|guidelines|rules|prompt)'
        . '|system\s+prompt|you\s+are\s+now|call\s+the\s+tool|\boverride\s*:|\bnew\s+instructions\b/i';

    /** @param list<string> $textFields */
    public function __construct(
        private readonly string $source,
        private readonly array $textFields,
    ) {
    }

    public function handle(Payload $payload, Closure $next): Payload
    {
        foreach ($payload->rows as $index => $row) {
            foreach ($this->textFields as $field) {
                if (! isset($row[$field]) || ! is_string($row[$field])) {
                    continue;
                }

                $text = $row[$field];

                if (preg_match(self::PHRASES, $text) === 1 && ! in_array(self::FLAG, $payload->flags, true)) {
                    $payload->flags[] = self::FLAG;
                }

                $payload->rows[$index][$field] = ['untrusted' => true, 'source' => $this->source, 'text' => $text];
            }
        }

        return $next($payload);
    }
}
