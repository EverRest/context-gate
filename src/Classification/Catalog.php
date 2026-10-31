<?php

declare(strict_types=1);

namespace ContextGate\Classification;

use InvalidArgumentException;

/**
 * The classification catalog: table, column and class. Validated once on construction.
 */
final class Catalog
{
    /** @var array<string, array<string, Ceiling>> */
    private array $classes = [];

    /** @param array<string, array<string, mixed>> $map table => column => class */
    public function __construct(array $map)
    {
        foreach ($map as $table => $columns) {
            foreach ($columns as $column => $value) {
                $class = is_string($value) ? Ceiling::tryFrom($value) : null;

                if ($class === null) {
                    throw new InvalidArgumentException(sprintf(
                        'Unknown class for %s.%s; expected public, internal, personal or secret.',
                        $table,
                        $column,
                    ));
                }

                $this->classes[$table][$column] = $class;
            }
        }
    }

    /** Loads the catalog file, which must return the table => column => class map. */
    public static function fromFile(string $path): self
    {
        /** @var array<string, array<string, mixed>> $map */
        $map = require $path;

        return new self($map);
    }

    public function classOf(string $table, string $column): ?Ceiling
    {
        return $this->classes[$table][$column] ?? null;
    }
}
