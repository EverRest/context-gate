<?php

declare(strict_types=1);

namespace ContextGate\Settings;

use RuntimeException;

/**
 * spec 007 R1: the active stored version is merged over the file config once, at boot. Maps merge key by key; a list
 * (such as a caller's tools) is replaced whole, so a version never mixes two lists.
 *
 * @internal May change without notice.
 */
final class ConfigOverlay
{
    /**
     * @param array<string, mixed> $fileConfig the context-gate config as loaded from the file
     * @return array<string, mixed>
     */
    public static function apply(array $fileConfig, ConfigVersionStore $store): array
    {
        $version = self::activeVersion($store);

        return $version === null ? $fileConfig : self::merge($fileConfig, $version);
    }

    /**
     * spec 007 amendment 2026-11-01: why the stored versions cannot be applied, without the hash. An unreadable index
     * or a missing active version file leaves the file config in place and makes the settings invalid.
     *
     * @return list<array{key: string, message: string}>
     */
    public static function violations(ConfigVersionStore $store): array
    {
        try {
            $active = $store->active();
        } catch (RuntimeException) {
            return [['key' => 'versions/index.json', 'message' => 'is unreadable; no stored version is applied']];
        }

        if ($active !== null && $store->find($active) === null) {
            return [['key' => 'versions', 'message' => 'the active version file is missing or unreadable']];
        }

        return [];
    }

    /** @return array<string, mixed>|null */
    private static function activeVersion(ConfigVersionStore $store): ?array
    {
        try {
            $active = $store->active();
        } catch (RuntimeException) {
            return null;
        }

        return $active === null ? null : $store->find($active);
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $over
     * @return array<array-key, mixed>
     */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            $existing = $base[$key] ?? null;
            $base[$key] = is_array($value) && is_array($existing) && ! array_is_list($value) && ! array_is_list($existing)
                ? self::merge($existing, $value)
                : $value;
        }

        return $base;
    }
}
