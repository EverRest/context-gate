<?php

declare(strict_types=1);

namespace ContextGate\Readiness;

/**
 * One line of gate:check output. Names only: subject is a setting key or file path, message names what is wrong,
 * never a value (FR-014).
 *
 * @internal May change without notice.
 */
final readonly class CheckResult
{
    private function __construct(
        public CheckStatus $status,
        public string $subject,
        public string $message,
        public string $fix,
    ) {
    }

    public static function pass(string $subject, string $message): self
    {
        return new self(CheckStatus::Pass, $subject, $message, '');
    }

    public static function warn(string $subject, string $message, string $fix): self
    {
        return new self(CheckStatus::Warn, $subject, $message, $fix);
    }

    public static function fail(string $subject, string $message, string $fix): self
    {
        return new self(CheckStatus::Fail, $subject, $message, $fix);
    }
}
