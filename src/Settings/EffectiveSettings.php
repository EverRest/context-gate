<?php

declare(strict_types=1);

namespace ContextGate\Settings;

use ContextGate\Config\CapsValidator;
use Illuminate\Support\Arr;

/**
 * spec 007 US2, FR-010: the value in use for each limit. An explicit value is used when it is within its cap; null,
 * or a value above its cap, takes the profile value.
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
        $caps = CapsValidator::caps();
        $values = [];

        foreach (Profiles::for($profile) as $key => $fallback) {
            $explicit = Arr::get($document, $key);
            $values[$key] = self::numeric($explicit) && $explicit <= $caps[$key] ? $explicit : $fallback;
        }

        return $values;
    }

    /** The value in use for one limit key, from the current config and environment. */
    public static function limit(string $key): int
    {
        $document = config('context-gate');
        $env = config('app.env');

        return (int) self::for(is_array($document) ? $document : [], is_string($env) ? $env : 'production')[$key];
    }

    private static function numeric(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
