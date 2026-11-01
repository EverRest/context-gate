<?php

declare(strict_types=1);

namespace ContextGate\Settings;

use ContextGate\Config\CapsValidator;
use ContextGate\Server\Tool;
use Illuminate\Support\Arr;

/**
 * spec 007 FR-001, FR-005, FR-011: the one validator for gate:check, import, rollback and boot. It returns every
 * violation with its key and never a value.
 *
 * @internal May change without notice.
 */
final class SettingsValidator
{
    /**
     * @param array<string, mixed> $document the settings document
     * @param array<string, int|float>|null $caps config key => code cap; the CapsValidator map when null
     * @return list<array{key: string, message: string}>
     */
    public static function violations(array $document, string $env, ?array $caps = null): array
    {
        $caps ??= CapsValidator::caps();

        $violations = SettingsSchema::violations($document);

        foreach ($caps as $key => $cap) {
            $value = Arr::get($document, $key);

            if (! is_int($value) && ! is_float($value)) {
                continue;
            }

            if ($value > $cap) {
                $violations[] = ['key' => $key, 'message' => 'is above its code cap'];
            } elseif ($value < 1) {
                $violations[] = ['key' => $key, 'message' => 'must be at least 1'];
            }
        }

        $profile = is_string($document['profile'] ?? null) ? SecurityProfile::tryFrom($document['profile']) : null;

        if (EnvironmentProfile::forced($env) && $profile === SecurityProfile::Balanced) {
            $violations[] = ['key' => 'profile', 'message' => 'must be strict when APP_ENV is production (spec 007 FR-005)'];
        }

        $names = self::toolNames((array) ($document['tools'] ?? []), $violations);

        foreach ((array) ($document['callers'] ?? []) as $id => $caller) {
            $granted = is_array($caller) ? ($caller['tools'] ?? []) : [];

            foreach ((array) $granted as $name) {
                if (! in_array($name, $names, true)) {
                    $violations[] = ['key' => "callers.{$id}.tools", 'message' => 'lists a tool that is not in tools'];

                    break;
                }
            }
        }

        return $violations;
    }

    /**
     * The names the registry keys each tool by (Tool::name(), ToolRegistry). A tools key that is not its tool's name is
     * a violation, so keys and names are the same and callers are checked against either.
     *
     * @param array<array-key, mixed> $tools
     * @param list<array{key: string, message: string}> $violations
     * @return list<string>
     */
    private static function toolNames(array $tools, array &$violations): array
    {
        $names = [];

        foreach ($tools as $key => $entry) {
            $class = is_array($entry) ? ($entry['class'] ?? null) : null;
            $name = is_string($class) && is_a($class, Tool::class, true) ? app($class)->name() : (string) $key;

            if ($name !== (string) $key) {
                $violations[] = ['key' => "tools.{$key}", 'message' => "must equal the tool name [{$name}], which is the name the registry uses"];
            }

            $names[] = $name;
        }

        return $names;
    }
}
