<?php

declare(strict_types=1);

namespace ContextGate\Token;

final class Ability
{
    public const READ = 'gate:read';

    public const CEILING_PREFIX = 'gate:ceiling:';

    /** @return list<string> */
    public static function package(): array
    {
        return [
            self::READ,
            ...array_map(static fn (Ceiling $ceiling): string => self::CEILING_PREFIX.$ceiling->value, Ceiling::cases()),
        ];
    }

    public static function isPackageAbility(string $name): bool
    {
        return in_array($name, self::package(), true);
    }
}
