<?php

declare(strict_types=1);

namespace ContextGate\Settings;

/**
 * spec 007 US1, FR-005: the profile an application environment runs with.
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

    public static function resolve(string $env, ?string $configured): SecurityProfile
    {
        if (self::forced($env)) {
            return SecurityProfile::Strict;
        }

        return SecurityProfile::tryFrom((string) $configured) ?? SecurityProfile::Strict;
    }
}
