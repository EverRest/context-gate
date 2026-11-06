<?php

declare(strict_types=1);

namespace ContextGate\Settings;

/**
 * The profile an application environment runs with.
 *
 * @internal May change without notice.
 */
final class EnvironmentProfile
{
    /** Environments that may choose a profile. Every other value, including production and unknown ones, is strict. */
    private const CONFIGURABLE = ['local', 'development', 'staging', 'testing'];

    /** True when the environment always runs strict, so a configured profile may not be weaker. */
    public static function forced(string $env): bool
    {
        return ! in_array($env, self::CONFIGURABLE, true);
    }

    /**
     * The same rule for the running application: every production guard (stdio refusal, preview, simulator, store and
     * transport checks) asks this, so an environment named prod or live is not treated as development by some of them.
     */
    public static function appForced(): bool
    {
        return self::forced((string) app()->environment());
    }

    public static function resolve(string $env, ?string $configured): SecurityProfile
    {
        if (self::forced($env)) {
            return SecurityProfile::Strict;
        }

        return SecurityProfile::tryFrom((string) $configured) ?? SecurityProfile::Strict;
    }
}
