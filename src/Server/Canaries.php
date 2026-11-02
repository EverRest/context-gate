<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Settings\ActiveSettings;

/** @internal May change without notice. */
final class Canaries
{
    /**
     * The configured canary values that are non-empty strings. An env() value that is unset reads as null, and
     * CanaryCheck takes strings only, so the other values are dropped.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        $canaries = [];

        foreach (app(ActiveSettings::class)->canaries() as $canary) {
            if ($canary !== '') {
                $canaries[] = $canary;
            }
        }

        return $canaries;
    }
}
