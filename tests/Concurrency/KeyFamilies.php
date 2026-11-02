<?php

declare(strict_types=1);

namespace ContextGate\Tests\Concurrency;

/**
 * Test-only: the maximum time to live of each key family the gate writes (spec 008 FR-010, research R3). Not runtime
 * code: the TTLs live in their owning classes, and this map checks them from outside.
 */
final class KeyFamilies
{
    /** @var array<string, int> pattern over the key without the run prefix => maximum TTL in seconds */
    private const FAMILIES = [
        '#^context-gate:rate:[^:]+(:timer)?$#' => 60,
        '#^context-gate:budget:session:#' => 3600,
        '#^context-gate:budget:day:#' => 86400,
        '#^context-gate:refusals:#' => 60,
        '#^context-gate:pseudonym:day:#' => 86400,
        '#^context-gate:pseudonym:week:#' => 604800,
        '#^context-gate:pseudonym:session:#' => 3600,
        '#^context-gate:lock:#' => 5,
        '#^context-gate:probe:#' => 5,
    ];

    /** The maximum TTL of the family the key belongs to, or null when no family matches (the sweep fails then). */
    public static function maxTtl(string $key): ?int
    {
        foreach (self::FAMILIES as $pattern => $max) {
            if (preg_match($pattern, $key) === 1) {
                return $max;
            }
        }

        return null;
    }
}
