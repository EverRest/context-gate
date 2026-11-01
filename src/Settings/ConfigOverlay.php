<?php

declare(strict_types=1);

namespace ContextGate\Settings;

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
        $active = $store->active();
        $version = $active === null ? null : $store->find($active);

        return $version === null ? $fileConfig : self::merge($fileConfig, $version);
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
