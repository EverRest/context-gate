<?php

declare(strict_types=1);

namespace ContextGate\Runbook;

/**
 * Status of one runbook step (spec 018 FR-008). Only ok and warn pass the run (FR-014).
 *
 * @internal May change without notice.
 */
enum StepStatus: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';
    case Timeout = 'timeout';
    case Error = 'error';

    public function exitCode(): int
    {
        return match ($this) {
            self::Ok, self::Warn => 0,
            self::Fail, self::Timeout, self::Error => 1,
        };
    }
}
