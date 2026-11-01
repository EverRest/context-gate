<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Classification\Catalog;
use ContextGate\Source\SchemaSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\Fixtures\StubSchemaConnection;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;

/**
 * F3, research R3: an enum column shows its type name, never the enum values. SQLite cannot declare an enum with
 * its values in the type, so the column rows are the ones MySQL reports (type "enum('pending','shipped')").
 */
class SchemaEnumColumnTest extends TestCase
{
    public function test_enum_values_are_not_shown_in_the_column_type(): void
    {
        SchemaEnumShipment::$stub = new StubSchemaConnection([
            ['name' => 'id', 'type_name' => 'int', 'type' => 'int'],
            ['name' => 'status', 'type_name' => 'enum', 'type' => "enum('pending','shipped')"],
        ]);
        $catalog = new Catalog(['shipments' => ['id' => 'public', 'status' => 'public']]);

        $result = (new SchemaSource($catalog, [SchemaEnumShipment::class]))->read(new SourceRequest(['model' => SchemaEnumShipment::class]));

        $this->assertTrue($result->ok);
        $json = (string) json_encode($result->data, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('pending', $json);
        $this->assertStringNotContainsString('shipped', $json);
    }
}

final class SchemaEnumShipment extends Model
{
    public static Connection $stub;

    protected $table = 'shipments';

    public function getConnection(): Connection
    {
        return self::$stub;
    }
}
