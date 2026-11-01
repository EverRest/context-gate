<?php

declare(strict_types=1);

namespace ContextGate\Runbook;

use ContextGate\Contracts\Runbook;

/**
 * The runbooks registered under configuration `runbooks`. Keys are runbook names, values are
 * class names. problems() reports names and classes that cannot be used; find() resolves a usable name to its runbook.
 *
 * @internal May change without notice.
 */
final class RunbookRegistry
{
    /** @param array<array-key, mixed> $map runbook name => class name */
    public function __construct(private readonly array $map)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('context-gate.runbooks', []));
    }

    /** @return array<string, string> name => class, in declared order; entries whose class is not a string are left out */
    public function all(): array
    {
        $all = [];

        foreach ($this->map as $name => $class) {
            if (is_string($class)) {
                $all[(string) $name] = $class;
            }
        }

        return $all;
    }

    /** @return list<array{key: string, message: string}> every name or class that cannot be used, with its key */
    public function problems(): array
    {
        $problems = [];
        $byLowerName = [];

        foreach ($this->map as $name => $class) {
            $key = (string) $name;
            $byLowerName[strtolower($key)][] = $key;

            if (preg_match(RunbookStep::NAME_PATTERN, $key) !== 1) {
                $problems[] = ['key' => $key, 'message' => 'name must match '.RunbookStep::NAME_PATTERN];
            }

            if (! is_string($class) || ! class_exists($class)) {
                $problems[] = ['key' => $key, 'message' => 'class does not exist'];
            }
        }

        foreach ($byLowerName as $keys) {
            if (count($keys) > 1) {
                foreach ($keys as $key) {
                    $problems[] = ['key' => $key, 'message' => 'duplicates another runbook name (case-insensitive)'];
                }
            }
        }

        return $problems;
    }

    /** The runbook registered under $name, or null when the name is not registered or its class is not a Runbook. */
    public function find(string $name): ?Runbook
    {
        $class = $this->map[$name] ?? null;

        if (! is_string($class) || ! is_a($class, Runbook::class, true)) {
            return null;
        }

        return app($class);
    }
}
