<?php

declare(strict_types=1);

namespace ContextGate\Settings;

/**
 * The shape of index.json. Every field that is used as a value or a path is checked, so a tampered
 * index never reaches a file path or a typed return. Hashes are sha256 hex; the other fields are strings.
 *
 * @internal May change without notice.
 */
final class ConfigIndexShape
{
    public static function wellFormed(mixed $index): bool
    {
        if (! is_array($index) || ! array_key_exists('active', $index) || ! is_array($index['versions'] ?? null) || ! self::isHashOrNull($index['active'])) {
            return false;
        }

        $seen = [];

        foreach ($index['versions'] as $entry) {
            if (! is_array($entry) || ! self::isHash($entry['hash'] ?? null) || ! self::isHashOrNull($entry['previous'] ?? null)
                || ! is_string($entry['actor'] ?? null) || ! is_string($entry['applied_at'] ?? null) || isset($seen[$entry['hash']])) {
                return false;
            }

            $seen[$entry['hash']] = true;
        }

        return true;
    }

    private static function isHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    private static function isHashOrNull(mixed $value): bool
    {
        return $value === null || self::isHash($value);
    }
}
