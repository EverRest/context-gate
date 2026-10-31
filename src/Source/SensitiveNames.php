<?php

declare(strict_types=1);

namespace ContextGate\Source;

/** Shared sensitive-name rule (research R6, FR-008). One list, one place to change. */
final class SensitiveNames
{
    /** @var list<string> */
    public const WORDS = ['secret', 'key', 'token', 'password', 'dsn', 'private', 'credentials'];

    /** A path or config-key segment of 20 or more key-like characters, or an address, is a value and not a name. */
    public static function looksSecret(string $segment): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{20,}$/', $segment) === 1 || str_contains($segment, '@');
    }

    public static function matches(string ...$names): bool
    {
        foreach ($names as $name) {
            $lower = strtolower($name);
            foreach (self::WORDS as $word) {
                if (str_contains($lower, $word)) {
                    return true;
                }
            }
        }

        return false;
    }
}
