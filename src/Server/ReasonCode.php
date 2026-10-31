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
    case CanaryHit = 'canary.hit';
    case BudgetSession = 'budget.session';
    case BudgetDaily = 'budget.daily';
    case RateLimited = 'rate.limited';
    case SourceMissing = 'source.missing';
    case SchemaMissing = 'schema.missing';
    case SourceRefused = 'source.refused';

    public function decision(): string
    {
        return match ($this) {
            self::PolicyHidden, self::ToolPinMismatch => 'hidden',
            self::InputInvalid => 'invalid',
            self::PolicyDenied => 'denied',
            self::PseudonymUnknown => 'refused',
            self::HandlerFailed, self::PseudonymKeyFail => 'failed',
            self::CanaryHit => 'blocked',
            self::BudgetSession, self::BudgetDaily, self::RateLimited => 'refused',
            self::SourceMissing, self::SchemaMissing => 'failed',
            self::SourceRefused => 'denied',
        };
    }
}
