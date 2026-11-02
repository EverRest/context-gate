<?php

declare(strict_types=1);

namespace ContextGate\Settings;

/**
 * "Setting keys": which keys a settings document may hold, their types, and the keys that are
 * forbidden. Unknown keys are reported, never ignored. Leaves are type names: bool, int? (integer or null),
 * float? (number or null), string (non-empty is string+), string? (null or string), list<string+> (list of non-empty
 * strings), list<string?> (strings or nulls), map<string,string>, enum:a|b, enum?:a|b (null or one of them). Arrays are sections with
 * their own allowed keys; a `*` key applies its section to any name (tool entries).
 *
 * @internal May change without notice.
 */
final class SettingsSchema
{
    private const TREE = [
        'enabled' => 'bool',
        'profile' => 'enum?:strict|balanced',
        'tools' => ['*' => ['enabled' => 'bool', 'class' => 'string', 'gate' => 'string?', 'fingerprint' => 'string?', 'contract' => 'string?', 'risk' => 'enum?:low|medium|high']],
        'runbooks' => ['*' => 'string'],
        'roles' => ['*' => [
            'tools' => 'list<string+>',
            'ceiling' => 'enum:public|internal|personal',
            'limits' => [
                'per_call_bytes' => 'int?',
                'per_session_bytes' => 'int?',
                'per_day_bytes' => 'int?',
                'calls_per_minute' => 'int?',
            ],
            'scope' => 'any',
        ]],
        'stdio_roles' => ['*' => 'string+'],
        'canaries' => ['values' => 'list<string?>'],
        'firewall' => [
            'ceiling' => 'enum:public|internal',
            'limits' => [
                'per_call_bytes' => 'int?',
                'per_session_bytes' => 'int?',
                'per_day_bytes' => 'int?',
                'calls_per_minute' => 'int?',
            ],
        ],
        'classification' => 'classification',
        'http' => ['path' => 'string+', 'origins' => 'list<string+>', 'refusals_per_minute' => 'int?'],
        'channel' => 'string+',
        'cache_store' => 'string?',
        'log_level' => 'enum:debug|info|notice|warning|error',
        'sources' => [
            'routes' => ['exclude' => 'list<string+>'],
            'eloquent' => ['models' => 'list<string+>'],
            'config' => ['env_map' => 'map<string,string>'],
            'docs' => ['paths' => 'list<string+>'],
            'openapi' => [
                'paths' => 'list<string+>',
                'allowlist' => 'list<string+>',
                'examples' => 'enum:drop|redacted',
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
        if ($name === 'callers') {
            return 'callers was replaced by roles; see docs/tokens.md';
        }

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
        if ($rule === 'classification') {
            return null; // the whole map is checked by ClassificationMerge::violations
        }

        if ($rule === 'any') {
            return null; // checked by the owning rule (a role's scope is validated by SettingsValidator)
        }

        if ($rule === 'bool') {
            return is_bool($value) ? null : 'must be true or false';
        }

        if ($rule === 'string') {
            return is_string($value) ? null : 'must be a string';
        }

        if ($rule === 'string+') {
            return is_string($value) && $value !== '' ? null : 'must be a non-empty string';
        }

        if ($rule === 'list<string+>') {
            return is_array($value) && array_is_list($value) && array_reduce($value, static fn (bool $ok, mixed $item): bool => $ok && is_string($item) && $item !== '', true)
                ? null
                : 'must be a list of non-empty strings';
        }

        if ($rule === 'list<string?>') {
            return is_array($value) && array_is_list($value) && array_reduce($value, static fn (bool $ok, mixed $item): bool => $ok && ($item === null || is_string($item)), true)
                ? null
                : 'must be a list of strings or nulls';
        }

        if ($rule === 'map<string,string>') {
            return is_array($value) && array_reduce(array_keys($value), static fn (bool $ok, int|string $key): bool => $ok && is_string($key) && is_string($value[$key]), true)
                ? null
                : 'must be a map of strings';
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
