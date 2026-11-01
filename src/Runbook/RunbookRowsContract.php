<?php

declare(strict_types=1);

namespace ContextGate\Runbook;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;

/**
 * The contract of a runbook report: one row per step. Text is Field::text(), so the firewall wraps it as
 * untrusted content and runs the redaction and canary stages on it.
 *
 * @internal May change without notice.
 */
final class RunbookRowsContract extends ContextContract
{
    public function fields(): array
    {
        return [
            'step' => Field::plain(),
            'status' => Field::plain(),
            'text' => Field::text(),
        ];
    }

    public function model(): string
    {
        return RunbookRow::class;
    }
}
