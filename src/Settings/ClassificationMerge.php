<?php

declare(strict_types=1);

namespace ContextGate\Settings;

/**
 * The effective classification map: the built-in source tables, with the host's hides applied, plus the host's own
 * tables. A host entry for a built-in table may only hide it or hide one of its columns; a repeat of the built-in
 * entry is no change.
 *
 * @internal May change without notice.
 */
final class ClassificationMerge
{
    private const PREFIX = 'source_';

    private const CLASSES = ['public', 'internal', 'personal', 'secret'];

    /**
     * The map in force. Only a valid host map is meant for this; an invalid entry is ignored here and reported by violations().
     *
     * @param array<array-key, mixed> $host
     * @return array<string, array<string, string>> table => column => class
     */
    public static function effective(array $host): array
    {
        $map = BuiltinClassification::TABLES;

        foreach ($host as $table => $entry) {
            $table = (string) $table;

            if (! str_starts_with($table, self::PREFIX)) {
                $map[$table] = (array) $entry;

                continue;
            }

            if ($entry === false) {
                unset($map[$table]);

                continue;
            }

            foreach ((array) $entry as $column => $class) {
                if ($class === false && isset($map[$table][(string) $column])) {
                    unset($map[$table][(string) $column]);
                }
            }
        }

        return $map;
    }

    /**
     * Why the host map cannot be applied, each with its key (classification.<table>[.<column>]) and no value.
     *
     * @return list<array{key: string, message: string}>
     */
    public static function violations(mixed $host): array
    {
        if (! is_array($host)) {
            return [['key' => 'classification', 'message' => 'must be a section of settings']];
        }

        $violations = [];

        foreach ($host as $table => $entry) {
            $table = (string) $table;

            if (str_starts_with($table, self::PREFIX)) {
                array_push($violations, ...self::builtinViolations($table, $entry));

                continue;
            }

            if (! is_array($entry)) {
                $violations[] = ['key' => "classification.{$table}", 'message' => 'must be a map of columns'];

                continue;
            }

            foreach ($entry as $column => $class) {
                if (! is_string($class) || ! in_array($class, self::CLASSES, true)) {
                    $violations[] = ['key' => "classification.{$table}.{$column}", 'message' => 'must be one of: '.implode(', ', self::CLASSES)];
                }
            }
        }

        return $violations;
    }

    /** @return list<array{key: string, message: string}> */
    private static function builtinViolations(string $table, mixed $entry): array
    {
        $key = "classification.{$table}";

        if (! isset(BuiltinClassification::TABLES[$table])) {
            return [['key' => $key, 'message' => 'the source_ prefix is reserved for the package']];
        }

        if ($entry === false) {
            return [];
        }

        if (! is_array($entry)) {
            return [['key' => $key, 'message' => 'must be false or a map of columns']];
        }

        $violations = [];
        $builtin = BuiltinClassification::TABLES[$table];

        foreach ($entry as $column => $class) {
            $columnKey = "{$key}.{$column}";

            if (! isset($builtin[(string) $column])) {
                $violations[] = ['key' => $columnKey, 'message' => 'a built-in source table cannot get new columns'];
            } elseif ($class !== false && $class !== $builtin[(string) $column]) {
                $violations[] = ['key' => $columnKey, 'message' => 'a built-in source column can only be hidden (false)'];
            }
        }

        return $violations;
    }
}
