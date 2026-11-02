<?php

declare(strict_types=1);

namespace ContextGate\Settings;

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

    private static function numeric(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
