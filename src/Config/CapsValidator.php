<?php

declare(strict_types=1);

namespace ContextGate\Config;

use ContextGate\Pipeline\Caps;

/**
 * Config narrows, never widens: a configured limit above its code cap is a violation. Used by gate:check.
 */
final class CapsValidator
{
    /** Config key => code cap. */
    private const CAPS = [
        'firewall.limits.per_call_bytes' => Caps::PER_CALL,
        'firewall.limits.per_session_bytes' => Caps::PER_SESSION,
        'firewall.limits.per_day_bytes' => Caps::PER_DAY,
        'firewall.limits.calls_per_minute' => Caps::PER_MINUTE,
        'http.refusals_per_minute' => Caps::REFUSALS_PER_MINUTE,
        'sources.openapi.timeout_seconds' => Caps::OPENAPI_TIMEOUT,
        'sources.openapi.max_bytes' => Caps::OPENAPI_BYTES,
    ];

    /** @return array<string, int|float> config key => code cap */
    public static function caps(): array
    {
        return self::CAPS;
    }

    /**
     * @param array<string, int|float> $configured config key => configured value
     * @return list<string> the keys whose value is above their cap
     */
    public static function violations(array $configured): array
    {
        $violations = [];

        foreach (self::CAPS as $key => $cap) {
            if (isset($configured[$key]) && $configured[$key] > $cap) {
                $violations[] = $key;
            }
        }

        return $violations;
    }
}
