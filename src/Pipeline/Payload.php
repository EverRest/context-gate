<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

/**
 * What the stages pass along: the rows, the projected fields, flags, dropped fields and the size.
 *
 * @internal May change without notice.
 */
final class Payload
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $fields projected field names, in contract order
     * @param list<string> $flags e.g. injection.phrase
     * @param list<array{field: string, reason: string}> $dropped
     */
    public function __construct(
        public array $rows,
        public array $fields = [],
        public array $flags = [],
        public array $dropped = [],
        public int $bytes = 0,
    ) {
    }
}
