<?php

declare(strict_types=1);

namespace ContextGate\Pipeline;

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

    private function __construct()
    {
    }
}
