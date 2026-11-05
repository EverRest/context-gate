<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

require_once __DIR__ . '/../../Fixtures/app/app/Models/Order.php';

use App\Models\Order;
use ContextGate\Classification\Catalog;
use ContextGate\Source\SchemaSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\Fixtures\StubSchemaConnection;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Connection;

/**
 * Index names are shown only when they cannot spell a column the caller does not see. getIndexes() reports these
 * shapes on PostgreSQL: an expression index has no columns, and a mixed one lists only its plain columns.
 */
class SchemaIndexNamesTest extends TestCase
{
    public function test_an_index_with_no_listed_column_is_not_shown(): void
    {
        $data = $this->describe([['name' => 'orders_lower_api_token_idx', 'columns' => [], 'unique' => false]]);

        $this->assertSame([], $data['indexes']);
        $this->assertStringNotContainsString('api_token', (string) json_encode($data));
    }

    public function test_a_shown_index_whose_name_spells_a_withheld_column_is_renamed(): void
    {
        $data = $this->describe([['name' => 'orders_status_lower_api_token_idx', 'columns' => ['status'], 'unique' => false]]);

        $this->assertSame([['name' => 'index_1', 'columns' => ['status'], 'unique' => false]], $data['indexes']);
        $this->assertStringNotContainsString('api_token', (string) json_encode($data));
    }

    public function test_an_index_on_shown_columns_keeps_its_name(): void
    {
        $data = $this->describe([['name' => 'orders_status_index', 'columns' => ['status'], 'unique' => true]]);

        $this->assertSame([['name' => 'orders_status_index', 'columns' => ['status'], 'unique' => true]], $data['indexes']);
    }

    /**
     * @param list<array{name: string, columns: list<string>, unique: bool}> $indexes
     * @return array<string, mixed>
     */
    private function describe(array $indexes): array
    {
        $model = new class () extends Order {
            public static Connection $stub;

            public function getConnection(): Connection
            {
                return self::$stub;
            }
        };
        $model::$stub = new StubSchemaConnection([
            ['name' => 'status', 'type_name' => 'varchar', 'type' => 'varchar(20)'],
            ['name' => 'api_token', 'type_name' => 'varchar', 'type' => 'varchar(80)'],
        ], $indexes);

        $result = (new SchemaSource(new Catalog(['orders' => ['status' => 'public', 'api_token' => 'secret']]), [$model::class]))
            ->read(new SourceRequest(['model' => $model::class]));

        $this->assertTrue($result->ok);

        return $result->data;
    }
}
