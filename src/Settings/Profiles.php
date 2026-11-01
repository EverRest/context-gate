<?php

declare(strict_types=1);

namespace ContextGate\Settings;

use ContextGate\Pipeline\Caps;

/**
 * spec 007 FR-003, research R2: the value of each limit key under each profile. Keys are the cap keys of
 * CapsValidator. Balanced is the code cap; strict is lower and is the default.
 */
final class Profiles
{
    /** @return array<string, int|float> key => value */
    public static function for(SecurityProfile $profile): array
    {
        return match ($profile) {
            SecurityProfile::Strict => [
                'firewall.limits.per_call_bytes' => 16384,
                'firewall.limits.per_session_bytes' => 409600,
                'firewall.limits.per_day_bytes' => 5242880,
                'firewall.limits.calls_per_minute' => 60,
                'sources.openapi.timeout_seconds' => 2.5,
                'sources.openapi.max_bytes' => 1048576,
            ],
            SecurityProfile::Balanced => [
                'firewall.limits.per_call_bytes' => Caps::PER_CALL,
                'firewall.limits.per_session_bytes' => Caps::PER_SESSION,
                'firewall.limits.per_day_bytes' => Caps::PER_DAY,
                'firewall.limits.calls_per_minute' => Caps::PER_MINUTE,
                'sources.openapi.timeout_seconds' => Caps::OPENAPI_TIMEOUT,
                'sources.openapi.max_bytes' => Caps::OPENAPI_BYTES,
            ],
        };
    }
}
