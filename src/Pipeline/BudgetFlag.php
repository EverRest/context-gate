<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

use ContextGate\Server\ReasonCode;

/**
 * The flags the budget stage sets. SESSION and DAILY are also the values of the ReasonCode of their refusal.
 *
 * @internal May change without notice.
 */
final class BudgetFlag
{
    public const CALL = 'budget.call';

    public const SESSION = 'budget.session';

    public const DAILY = 'budget.daily';

    /** The refusal flags, in the order they are reported. */
    public const REFUSALS = [self::SESSION, self::DAILY];

    /** The ReasonCode of a budget refusal flag; the one place the two vocabularies meet. */
    public static function reason(string $flag): ReasonCode
    {
        return match ($flag) {
            self::SESSION => ReasonCode::BudgetSession,
            self::DAILY => ReasonCode::BudgetDaily,
            default => throw new \InvalidArgumentException("[{$flag}] is not a budget refusal flag."),
        };
    }

    private function __construct()
    {
    }
}
