<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Classification\Catalog;
use ContextGate\Source\SchemaSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * F3, research R3: an enum column shows its type name, never the enum values. SQLite cannot declare an enum with
 * its values in the type, so the column rows are the ones MySQL reports (type "enum('pending','shipped')").
 */
class SchemaEnumColumnTest extends TestCase
{
    public function test_enum_values_are_not_shown_in_the_column_type(): void
    {
        Schema::shouldReceive('hasTable')->with('shipments')->andReturnTrue();
        Schema::shouldReceive('getColumns')->with('shipments')->andReturn([
            ['name' => 'id', 'type_name' => 'int', 'type' => 'int'],
            ['name' => 'status', 'type_name' => 'enum', 'type' => "enum('pending','shipped')"],
        ]);
        Schema::shouldReceive('getIndexes')->with('shipments')->andReturn([]);
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
    protected $table = 'shipments';
}
