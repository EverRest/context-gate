<?php

declare(strict_types=1);

namespace ContextGate\Settings;

use ContextGate\Config\CapsValidator;
use Illuminate\Support\Arr;

/**
 * spec 007 FR-001, FR-005, FR-011: the one validator for gate:check, import, rollback and boot. It returns every
 * violation with its key and never a value.
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

            if ((is_int($value) || is_float($value)) && $value > $cap) {
                $violations[] = ['key' => $key, 'message' => 'is above its code cap'];
            }
        }

        $profile = is_string($document['profile'] ?? null) ? SecurityProfile::tryFrom($document['profile']) : null;

        if (EnvironmentProfile::forced($env) && $profile === SecurityProfile::Balanced) {
            $violations[] = ['key' => 'profile', 'message' => 'must be strict when APP_ENV is production (spec 007 FR-005)'];
        }

        $tools = array_keys((array) ($document['tools'] ?? []));

        foreach ((array) ($document['callers'] ?? []) as $id => $caller) {
            $granted = is_array($caller) ? ($caller['tools'] ?? []) : [];

            foreach ((array) $granted as $name) {
                if (! in_array($name, $tools, true)) {
                    $violations[] = ['key' => "callers.{$id}.tools", 'message' => 'lists a tool that is not in tools'];

                    break;
                }
            }
        }

        return $violations;
    }
}
