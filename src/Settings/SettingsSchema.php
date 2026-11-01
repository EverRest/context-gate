<?php

declare(strict_types=1);

namespace ContextGate\Settings;

/**
 * "Setting keys": which keys a settings document may hold, their types, and the keys that are
 * forbidden. Unknown keys are reported, never ignored. Leaves are type names: any, bool, int?
 * (integer or null), float? (number or null), enum:a|b, enum?:a|b (null or one of them). Arrays are sections with
 * their own allowed keys; a `*` key applies its section to any name (tool entries).
 *
 * @internal May change without notice.
 */
final class SettingsSchema
{
    private const TREE = [
        'enabled' => 'bool',
        'profile' => 'enum?:strict|balanced',
        'tools' => ['*' => ['enabled' => 'bool', 'class' => 'string', 'gate' => 'string?', 'fingerprint' => 'string?', 'contract' => 'string?']],
        'runbooks' => ['*' => 'string'],
        'callers' => ['*' => ['tools' => 'any']],
        'canaries' => ['values' => 'any'],
        'firewall' => [
            'ceiling' => 'enum:public|internal',
            'limits' => [
                'per_call_bytes' => 'int?',
                'per_session_bytes' => 'int?',
                'per_day_bytes' => 'int?',
                'calls_per_minute' => 'int?',
            ],
        ],
        'classification' => 'any',
        'http' => ['path' => 'any', 'origins' => 'any', 'refusals_per_minute' => 'int?'],
        'channel' => 'any',
        'cache_store' => 'string?',
        'log_level' => 'enum:debug|info|notice|warning|error',
        'sources' => [
            'routes' => ['exclude' => 'any'],
            'eloquent' => ['models' => 'any'],
            'config' => ['env_map' => 'any'],
            'docs' => ['paths' => 'any'],
            'openapi' => [
                'paths' => 'any',
                'allowlist' => 'any',
                'examples' => 'any',
                'access' => 'enum:read',
                'timeout_seconds' => 'float?',
                'max_bytes' => 'int?',
            ],
        ],
    ];

    /**
     * @param array<string, mixed> $document
     * @return list<array{key: string, message: string}> every violation of the document's shape, with its key
     */
    public static function violations(array $document): array
    {
        $violations = [];

        self::walk($document, self::TREE, '', $violations);

        return $violations;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param array<string, mixed> $tree
     * @param list<array{key: string, message: string}> $violations
     */
    private static function walk(array $node, array $tree, string $path, array &$violations): void
    {
        foreach ($node as $name => $value) {
            $key = $path === '' ? (string) $name : "{$path}.{$name}";

            if (! array_key_exists($name, $tree) && ! array_key_exists('*', $tree)) {
                $violations[] = ['key' => $key, 'message' => self::unknownMessage((string) $name)];

                continue;
            }

            $rule = $tree[$name] ?? $tree['*'];

            if (is_array($rule)) {
                if (! is_array($value)) {
                    $violations[] = ['key' => $key, 'message' => 'must be a section of settings'];

                    continue;
                }

                self::walk($value, $rule, $key, $violations);

                continue;
            }

            $message = self::leafMessage($key, $rule, $value);

            if ($message !== null) {
                $violations[] = ['key' => $key, 'message' => $message];
            }
        }
    }

    private static function unknownMessage(string $name): string
    {
        if ($name === 'redaction') {
            return 'redaction cannot be disabled by configuration (spec 007 FR-006)';
        }

        if (preg_match('/stage|pipeline/i', $name) === 1) {
            return 'names a pipeline stage: stages and their order are fixed in code (spec 007 FR-004)';
        }

        return 'is not a setting';
    }

    private static function leafMessage(string $key, string $rule, mixed $value): ?string
    {
        if ($rule === 'any') {
            return null;
        }

        if ($rule === 'bool') {
            return is_bool($value) ? null : 'must be true or false';
        }

        if ($rule === 'string') {
            return is_string($value) ? null : 'must be a string';
        }

        if ($rule === 'int?') {
            return $value === null || is_int($value) ? null : 'must be an integer or null';
        }

        if ($rule === 'string?') {
            return $value === null || is_string($value) ? null : 'must be a string or null';
        }

        if ($rule === 'float?') {
            return $value === null || is_int($value) || is_float($value) ? null : 'must be a number or null';
        }

        $nullable = str_starts_with($rule, 'enum?:');
        $allowed = explode('|', substr($rule, strlen($nullable ? 'enum?:' : 'enum:')));

        if ($nullable && $value === null) {
            return null;
        }

        if (is_string($value) && in_array($value, $allowed, true)) {
            return null;
        }

        if ($key === 'sources.openapi.access') {
            return 'connector access is read-only in this MVP (spec 007 FR-009)';
        }

        return 'must be one of: '.implode(', ', $allowed);
    }
}
