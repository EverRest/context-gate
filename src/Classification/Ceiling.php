<?php

declare(strict_types=1);

namespace ContextGate\Classification;

/**
 * Data classes, ordered from least to most sensitive. Also used as a caller ceiling.
 */
enum Ceiling: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Personal = 'personal';
    case Secret = 'secret';

    public static function allows(self $class, self $ceiling): bool
    {
        return $class->rank() <= $ceiling->rank();
    }

    /** The less sensitive of two ceilings: a caller's effective ceiling is the narrower of its own and its role's. */
    public static function narrower(self $a, self $b): self
    {
        return $a->rank() <= $b->rank() ? $a : $b;
    }

    private function rank(): int
    {
        return match ($this) {
            self::Public => 0,
            self::Internal => 1,
            self::Personal => 2,
            self::Secret => 3,
        };
    }
}
