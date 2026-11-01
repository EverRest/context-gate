<?php

declare(strict_types=1);

namespace ContextGate\Source;

use ContextGate\Server\ReasonCode;

/** A refusal carries the policy step name only, never the path. */
final readonly class SourceResult
{
    /** @param array<string, mixed> $data */
    private function __construct(
        public bool $ok,
        public array $data,
        public ?ReasonCode $reason,
        public ?string $refusalStep,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function ok(array $data): self
    {
        return new self(true, $data, null, null);
    }

    public static function refused(string $step): self
    {
        return new self(false, [], ReasonCode::SourceRefused, $step);
    }

    public static function failed(ReasonCode $reason): self
    {
        return new self(false, [], $reason, null);
    }
}
