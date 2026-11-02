<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use ContextGate\Server\ToolRegistry;
use ContextGate\Settings\ToolEntry;

/** Builds a ToolRegistry from tool entries written as configuration arrays (the shape the settings validate). */
final class Registry
{
    /** @param array<string, array<string, mixed>> $config */
    public static function of(array $config): ToolRegistry
    {
        $entries = [];

        foreach ($config as $name => $entry) {
            $entries[(string) $name] = ToolEntry::fromConfig((string) $name, $entry);
        }

        return new ToolRegistry($entries);
    }
}
