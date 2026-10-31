<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use Closure;
use ContextGate\Classification\Catalog;
use ContextGate\Classification\Ceiling;

/**
 * Stage 2 (R1): drops fields with no catalog class and fields above the caller ceiling. Fail closed.
 */
final class ClassifyCheck implements Stage
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly Ceiling $ceiling,
        private readonly string $table,
    ) {
    }

    public function handle(Payload $payload, Closure $next): Payload
    {
        $kept = [];

        foreach ($payload->fields as $field) {
            $class = $this->catalog->classOf($this->table, $field);

            if ($class === null) {
                $payload->dropped[] = ['field' => $field, 'reason' => 'field.unclassified'];
            } elseif (! Ceiling::allows($class, $this->ceiling)) {
                $payload->dropped[] = ['field' => $field, 'reason' => 'ceiling.exceeded'];
            } else {
                $kept[] = $field;
            }
        }

        $payload->fields = $kept;

        return $next($payload);
    }
}
