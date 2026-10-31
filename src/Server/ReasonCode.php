<?php

declare(strict_types=1);

namespace ContextGate\Server;

enum ReasonCode: string
{
    case PolicyHidden = 'policy.hidden';
    case ToolPinMismatch = 'tool.pin_mismatch';
    case InputInvalid = 'input.invalid';
    case PolicyDenied = 'policy.denied';
    case HandlerFailed = 'handler.failed';
    case PseudonymUnknown = 'pseudonym.unknown';
    case PseudonymKeyFail = 'pseudonym.keyfail';

    public function decision(): string
    {
        return match ($this) {
            self::PolicyHidden, self::ToolPinMismatch => 'hidden',
            self::InputInvalid => 'invalid',
            self::PolicyDenied => 'denied',
            self::PseudonymUnknown => 'refused',
            self::HandlerFailed, self::PseudonymKeyFail => 'failed',
        };
    }
}
