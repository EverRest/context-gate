<?php

declare(strict_types=1);

namespace ContextGate\Readiness;

final class CheckRegistry
{
    /** @return list<class-string<Check>> */
    public static function all(): array
    {
        return [];
    }
}
