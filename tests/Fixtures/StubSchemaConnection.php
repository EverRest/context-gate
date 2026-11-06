<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use PDO;

/** A real Connection whose schema builder reports the given column rows, as MySQL would. */
final class StubSchemaConnection extends Connection
{
    /**
     * @param list<array{name: string, type_name: string, type: string}> $columns
     * @param list<array{name: string, columns: list<string>, unique: bool}> $indexes
     */
    public function __construct(private readonly array $columns, private readonly array $indexes = [])
    {
        parent::__construct(new PDO('sqlite::memory:'));
    }

    public function getSchemaBuilder(): Builder
    {
        return new class ($this, $this->columns, $this->indexes) extends Builder {
            /**
             * @param list<array{name: string, type_name: string, type: string}> $columns
             * @param list<array{name: string, columns: list<string>, unique: bool}> $indexes
             */
            public function __construct(Connection $connection, private readonly array $columns, private readonly array $indexes)
            {
                parent::__construct($connection);
            }

            public function hasTable($table)
            {
                return true;
            }

            public function getColumns($table)
            {
                return array_map(static fn (array $c): array => $c + [
                    'collation' => null, 'nullable' => false, 'default' => null, 'auto_increment' => false, 'comment' => null,
                    'generation' => null,
                ], $this->columns);
            }

            public function getIndexes($table)
            {
                return array_map(static fn (array $i): array => $i + ['type' => 'btree', 'primary' => false], $this->indexes);
            }
        };
    }
}
