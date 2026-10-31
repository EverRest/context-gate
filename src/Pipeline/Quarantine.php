<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use Closure;

/**
 * Stage 9 (R9): wraps each text field as untrusted content with its source name. Injection phrases set the
 * injection.phrase flag. The stage never blocks: a flag is a signal, not a control (FR-016).
 */
final class Quarantine implements Stage
{
    private const FLAG = 'injection.phrase';

    private const PHRASES = '/ignore (?:all |the )?(?:previous|prior|above) instructions|system prompt|you are now'
        . '|call the tool|forget your (?:guidelines|instructions)|\boverride:|\bnew instructions\b/i';

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
