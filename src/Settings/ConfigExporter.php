<?php

declare(strict_types=1);

namespace ContextGate\Settings;

/**
 * The settings document without canary values. The canary section is dropped whole;
 * a scalar key whose name looks like a secret is dropped as a second guard. Maps (such as the classification catalog)
 * are kept even when a key name matches, because a table name is not a secret.
 *
 * @internal May change without notice.
 */
final class ConfigExporter
{
    private const SECRET_NAME = '/secret|token|password|key|credential/i';

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function export(array $document): array
    {
        unset($document['canaries']);

        return self::strip($document);
    }

    /**
     * @param array<array-key, mixed> $node
     * @return array<array-key, mixed>
     */
    private static function strip(array $node): array
    {
        $kept = [];

        foreach ($node as $name => $value) {
            if (is_array($value)) {
                $kept[$name] = self::strip($value);

                continue;
            }

            if (is_string($name) && preg_match(self::SECRET_NAME, $name) === 1) {
                continue;
            }

            $kept[$name] = $value;
        }

        return $kept;
    }
}
