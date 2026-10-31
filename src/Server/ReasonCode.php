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
    case TransformFailed = 'transform.failed';
    case ConfigInvalid = 'config.invalid';
    case CanaryHit = 'canary.hit';
    case BudgetSession = 'budget.session';
    case BudgetDaily = 'budget.daily';
    case RateLimited = 'rate.limited';
    case SourceMissing = 'source.missing';
    case SchemaMissing = 'schema.missing';
    case SourceRefused = 'source.refused';
    case EgressDenied = 'egress.denied';
    case SourceRedirect = 'source.redirect';
    case SourceTimeout = 'source.timeout';
    case SourceTooLarge = 'source.too_large';
    case OpenApiVersion = 'openapi.version';
    case TokenMissing = 'token.missing';
    case TokenInvalid = 'token.invalid';
    case TokenNoRead = 'token.no_read';
    case TokenNoCeiling = 'token.no_ceiling';
    case OriginDenied = 'origin.denied';
    case TokenAbility = 'token.ability';

    public function decision(): string
    {
        return match ($this) {
            self::PolicyHidden, self::ToolPinMismatch => 'hidden',
            self::InputInvalid => 'invalid',
            self::PolicyDenied => 'denied',
            self::PseudonymUnknown, self::ConfigInvalid => 'refused',
            self::TokenMissing, self::TokenInvalid, self::TokenNoRead, self::TokenNoCeiling, self::TokenAbility => 'refused',
            self::HandlerFailed, self::PseudonymKeyFail, self::TransformFailed => 'failed',
            self::CanaryHit => 'blocked',
            self::BudgetSession, self::BudgetDaily, self::RateLimited => 'refused',
            self::SourceMissing, self::SchemaMissing => 'failed',
            self::SourceRefused, self::EgressDenied, self::SourceRedirect,
            self::SourceTooLarge, self::OpenApiVersion, self::OriginDenied => 'denied',
            self::SourceTimeout => 'failed',
        };
    }
}
