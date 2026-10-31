<?php

declare(strict_types=1);

namespace ContextGate\Source;

/** Shared sensitive-name rule (research R6, FR-008). One list, one place to change. */
final class SensitiveNames
{
    /** @var list<string> */
    public const WORDS = ['secret', 'key', 'token', 'password', 'dsn', 'private', 'credentials'];

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
