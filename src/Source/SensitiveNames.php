<?php

declare(strict_types=1);

namespace ContextGate\Source;

/** Shared sensitive-name rule (research R6, FR-008). One list, one place to change. */
final class SensitiveNames
{
    /** @var list<string> */
    public const WORDS = ['secret', 'key', 'token', 'password', 'dsn', 'private', 'credentials'];

    /**
     * Shared masking rule for config-key and route segments (FR-007). A segment is shown only when it is
     * identifier-like and does not look like a token; everything else is a value and not a name.
     * Config keys: ^[A-Za-z_][A-Za-z0-9_-]{0,40}$ (or up to 6 digits). Route segments: the same characters plus
     * `.` and `-` up to 64, with `{param}` placeholders removed first. Token-like (masked either way): letters
     * and digits mixed at 16+ characters, upper+lower+digit mixed at 8+, 20+ letters only, 20+ hex.
     */
    public static function looksSecret(string $segment): bool
    {
        $plain = preg_replace('/\{[A-Za-z_][A-Za-z0-9_]*\??\}/', '', $segment) ?? $segment;

        return preg_match('/^[A-Za-z0-9_.-]{0,64}$/', $plain) !== 1 || self::tokenLike($plain);
    }

    public static function looksSecretKey(string $key): bool
    {
        return preg_match('/^([A-Za-z_][A-Za-z0-9_-]{0,40}|[0-9]{1,6})$/', $key) !== 1 || self::tokenLike($key);
    }

    /** A hyphen is a word separator, so a token split by hyphens is judged with the hyphens removed too. */
    private static function tokenLike(string $s): bool
    {
        return self::tokenShaped($s) || self::tokenShaped(str_replace('-', '', $s)) || str_contains($s, '@');
    }

    private static function tokenShaped(string $s): bool
    {
        $len = strlen($s);

        return ($len >= 16 && preg_match('/[A-Za-z]/', $s) === 1 && preg_match('/[0-9]/', $s) === 1)
            || ($len >= 8 && preg_match('/[a-z]/', $s) === 1 && preg_match('/[A-Z]/', $s) === 1 && preg_match('/[0-9]/', $s) === 1)
            || preg_match('/^[A-Za-z]{20,}$/', $s) === 1
            || preg_match('/^[0-9a-fA-F]{20,}$/', $s) === 1;
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
