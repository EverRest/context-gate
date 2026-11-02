<?php

declare(strict_types=1);

namespace ContextGate\Token;

final class Ability
{
    public const READ = 'gate:read';

    public const CEILING_PREFIX = 'gate:ceiling:';

    /** At most one per HTTP token: the role name, set at issuance. */
    public const ROLE_PREFIX = 'gate:role:';

    /** A role name: a plain identifier, 1 to 64 characters. */
    private const ROLE_NAME = '/^[A-Za-z0-9_-]{1,64}$/';

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
        if (str_starts_with($name, self::ROLE_PREFIX)) {
            return preg_match(self::ROLE_NAME, substr($name, strlen(self::ROLE_PREFIX))) === 1;
        }

        return in_array($name, self::package(), true);
    }
}
