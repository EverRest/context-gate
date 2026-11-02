<?php

declare(strict_types=1);

namespace ContextGate\Settings;

use ContextGate\Classification\Ceiling;
use Illuminate\Support\Arr;

/**
 * The value in use for each limit: the explicit value, or the profile value when it is null. A value above its
 * cap is not replaced here; SettingsValidator makes such a document invalid, so no consumer reads it.
 *
 * @internal May change without notice.
 */
final class EffectiveSettings
{
    /**
     * @param array<string, mixed> $document
     * @return array<string, int|float> cap key => value in use
     */
    public static function for(array $document, string $env): array
    {
        $profile = EnvironmentProfile::resolve($env, is_string($document['profile'] ?? null) ? $document['profile'] : null);
        $values = [];

        foreach (Profiles::for($profile) as $key => $fallback) {
            $explicit = Arr::get($document, $key);
            $values[$key] = self::numeric($explicit) ? $explicit : $fallback;
        }

        return $values;
    }

    /** The value in use for one limit key, from the current config and environment. */
    public static function limit(string $key): int
    {
        return (int) self::current()[$key];
    }

    /**
     * The values in use, from the current config and environment.
     *
     * @return array<string, int|float> cap key => value in use
     */
    public static function current(): array
    {
        return self::for(self::document(), self::environment());
    }

    /**
     * The settings document in config.
     *
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        $document = config('context-gate');

        return is_array($document) ? $document : [];
    }

    /** The environment profiles are chosen for. An unset or empty APP_ENV is production, which is strict. */
    public static function environment(): string
    {
        $env = config('app.env');

        return is_string($env) && $env !== '' ? $env : 'production';
    }

    /**
     * The configured ceiling class, or the code default internal when none is set. Only public and
     * internal are accepted; any other value is null, so the caller refuses (fail closed).
     */
    public static function ceiling(): ?Ceiling
    {
        $value = Arr::get(self::document(), 'firewall.ceiling', Ceiling::Internal->value);
        $ceiling = is_string($value) ? Ceiling::tryFrom($value) : null;

        return in_array($ceiling, [Ceiling::Public, Ceiling::Internal], true) ? $ceiling : null;
    }

    /**
     * The OpenAPI example mode in use: drop by default, redacted when configured. Null for any other value, so the
     * caller treats it as drop and gate:check reports it.
     */
    public static function examples(): ?string
    {
        $value = Arr::get(self::document(), 'sources.openapi.examples', 'drop');

        return in_array($value, ['drop', 'redacted'], true) ? $value : null;
    }

    private static function numeric(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
