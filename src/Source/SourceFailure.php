<?php

declare(strict_types=1);

namespace ContextGate\Source;

use ContextGate\Server\ReasonCode;
use RuntimeException;

/** Thrown by a source tool when its source refuses or fails. GateCallTool writes the receipt and the response. */
final class SourceFailure extends RuntimeException
{
    public function __construct(public readonly ReasonCode $reason, public readonly ?string $refusalStep = null)
    {
        parent::__construct('Source failed: ' . $reason->value);
    }

    public static function from(SourceResult $result): self
    {
        return new self($result->reason ?? ReasonCode::SourceMissing, $result->refusalStep);
    }
}
