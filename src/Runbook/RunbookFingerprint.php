<?php

declare(strict_types=1);

namespace ContextGate\Runbook;

use ContextGate\Contracts\Runbook;
use ContextGate\Server\Fingerprint;

/**
 * The approved-state pin of a runbook (spec 018 FR-020): name, description and each step's name, readOnly, timeout and
 * output size, in declared order. Reuses the canonical JSON of the tool fingerprint (R6).
 *
 * @internal May change without notice.
 */
final class RunbookFingerprint
{
    public static function of(Runbook $runbook): string
    {
        return Fingerprint::digest([
            'name' => $runbook->name(),
            'description' => $runbook->description(),
            'steps' => array_map(
                fn (RunbookStep $step): array => [
                    'name' => $step->name,
                    'readOnly' => $step->readOnly,
                    'timeoutSeconds' => $step->timeoutSeconds,
                    'maxBytes' => $step->maxBytes,
                ],
                $runbook->steps(),
            ),
        ]);
    }
}
