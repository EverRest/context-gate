<?php

declare(strict_types=1);

namespace ContextGate\Settings;

use ContextGate\Config\CapsValidator;
use Illuminate\Support\Arr;

/**
 * spec 007 US2, FR-010: the value in use for each limit. An explicit value is used when it is within its cap; null,
 * or a value above its cap, takes the profile value. A per-tool value is clamped to the global value (research R2).
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

    /**
     * @param array<string, mixed> $document
     * @return array{max_bytes: int|float, calls_per_minute: int|float} a tool's value in use, never above the global one
     */
    public static function tool(array $document, string $env, string $name): array
    {
        $global = self::for($document, $env);
        $own = is_array($document['tools'][$name] ?? null) ? $document['tools'][$name] : [];

        return [
            'max_bytes' => self::clamp($own['max_bytes'] ?? null, $global['firewall.limits.per_call_bytes']),
            'calls_per_minute' => self::clamp($own['calls_per_minute'] ?? null, $global['firewall.limits.calls_per_minute']),
        ];
    }

    private static function clamp(mixed $own, int|float $global): int|float
    {
        return self::numeric($own) ? min($own, $global) : $global;
    }

    private static function numeric(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
