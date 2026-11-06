<?php

declare(strict_types=1);

namespace ContextGate\Settings;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * The active stored version is merged over the file config once, at boot. Maps merge key by key; a list
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
     * Boot: keeps the file config, merges the active stored version over it, and binds what the settings need. Returns the
     * log channel receipts go to, so the provider can define it. Runs once per boot, before ActiveSettings is resolved.
     */
    public static function install(Application $app): string
    {
        $versions = ConfigVersionStore::fromApp();
        $file = config('context-gate');
        $app->instance('context-gate.file', is_array($file) ? $file : []);
        config()->set('context-gate', self::apply(is_array($file) ? $file : [], $versions));
        $app->instance('context-gate.overlay', self::violations($versions));

        // Read, not validated: validating here would build every tool before the application's providers have bound
        // what the tools need, and a tool that cannot be built yet would fail the host's boot.
        $channel = config('context-gate.channel');

        return is_string($channel) && $channel !== '' ? $channel : ActiveSettings::CHANNEL;
    }

    /**
     * Why the stored versions cannot be applied, without the hash. An unreadable index
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

    /**
     * A document is judged as boot will see it, merged over the file config. Import and rollback use
     * this, so a document that is valid alone but invalid merged is refused before anything is written.
     *
     * @param array<string, mixed> $document
     * @return list<array{key: string, message: string}>
     */
    public static function mergedViolations(array $document, string $env): array
    {
        return SettingsValidator::violations(self::merge(self::fileConfig(), $document), $env);
    }

    /**
     * The file config as the provider loaded it, before any stored version was merged over it (the provider binds it).
     *
     * @return array<string, mixed>
     */
    private static function fileConfig(): array
    {
        $file = app()->bound('context-gate.file') ? app('context-gate.file') : config('context-gate');

        return is_array($file) ? $file : [];
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
