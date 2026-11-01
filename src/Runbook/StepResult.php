<?php

declare(strict_types=1);

namespace ContextGate\Runbook;

/**
 * What one step reports: a status and a short text.
 *
 * @internal May change without notice.
 */
final readonly class StepResult
{
    public function __construct(
        public StepStatus $status,
        public string $text,
    ) {
    }
}
