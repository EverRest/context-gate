<?php

declare(strict_types=1);

namespace ContextGate\Settings;

use ContextGate\Config\CapsValidator;
use ContextGate\Server\Tool;
use ContextGate\Server\ToolRegistry;
use Illuminate\Support\Arr;
use Throwable;

/**
 * The one validator for gate:check, import, rollback and boot. It returns every
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

        if (array_key_exists('classification', $document)) {
            array_push($violations, ...ClassificationMerge::violations($document['classification']));
        }

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

        array_push($violations, ...self::roleViolations($document, $env, $names));

        return $violations;
    }

    /**
     * Roles name tools in `tools`, a ceiling (required), limits no higher than the profile value in force, and an empty
     * scope. A stdio user maps to a defined role.
     *
     * @param array<string, mixed> $document
     * @param list<string> $names the tool names (toolNames)
     * @return list<array{key: string, message: string}>
     */
    private static function roleViolations(array $document, string $env, array $names): array
    {
        $violations = [];
        $roles = is_array($document['roles'] ?? null) ? $document['roles'] : [];
        $profile = EffectiveSettings::for($document, $env);

        foreach ($roles as $name => $role) {
            $key = "roles.{$name}";

            if (! preg_match('/^[A-Za-z0-9_-]{1,64}\z/', (string) $name)) {
                $violations[] = ['key' => $key, 'message' => 'must be 1 to 64 letters, digits, _ or -'];
            }

            if (! is_array($role)) {
                continue;
            }

            foreach ((array) ($role['tools'] ?? []) as $tool) {
                if (! in_array($tool, $names, true)) {
                    $violations[] = ['key' => "{$key}.tools", 'message' => 'lists a tool that is not in tools'];

                    break;
                }
            }

            if (! array_key_exists('ceiling', $role)) {
                $violations[] = ['key' => "{$key}.ceiling", 'message' => 'is required'];
            }

            if (array_key_exists('scope', $role) && $role['scope'] !== []) {
                $violations[] = ['key' => "{$key}.scope", 'message' => 'is reserved for row scopes (spec 014); it must be absent or empty'];
            }

            foreach ((array) ($role['limits'] ?? []) as $limit => $value) {
                $cap = Role::LIMIT_KEYS[$limit] ?? null;

                if ($cap !== null && (is_int($value) || is_float($value)) && $value > $profile[$cap]) {
                    $violations[] = ['key' => "{$key}.limits.{$limit}", 'message' => 'is above the profile value in force'];
                }
            }
        }

        foreach ((array) ($document['stdio_roles'] ?? []) as $user => $role) {
            if (! is_string($role) || ! array_key_exists($role, $roles)) {
                $violations[] = ['key' => "stdio_roles.{$user}", 'message' => 'names a role that is not defined in roles'];
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
            $name = (string) $key;

            // A class that does not exist yet is allowed (a disabled entry may name one); one that exists must be a tool.
            if (is_string($class) && class_exists($class) && ! is_a($class, Tool::class, true)) {
                $violations[] = ['key' => "tools.{$key}.class", 'message' => 'must extend '.Tool::class];
            } elseif (is_string($class) && class_exists($class)) {
                try {
                    $name = ToolRegistry::instantiate((string) $key, $class)->name();
                } catch (Throwable $e) {
                    // The class only: a message can carry a value.
                    $violations[] = ['key' => "tools.{$key}.class", 'message' => 'could not be built ('.$e::class.')'];
                }
            }

            if ($name !== (string) $key) {
                $violations[] = ['key' => "tools.{$key}", 'message' => "must equal the tool name [{$name}], which is the name the registry uses"];
            }

            $names[] = $name;
        }

        return $names;
    }
}
