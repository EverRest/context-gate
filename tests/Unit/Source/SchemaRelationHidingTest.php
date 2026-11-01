<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

require_once __DIR__ . '/../../Fixtures/app/app/Models/User.php';

require_once __DIR__ . '/../../Fixtures/app/app/Models/Order.php';

use App\Models\Order;
use App\Models\User;
use ContextGate\Classification\Catalog;
use ContextGate\Source\SchemaSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\Fixtures\StubSchemaConnection;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SchemaRelationHidingTest extends TestCase
{
    public function test_the_class_of_a_related_model_outside_the_list_is_not_shown(): void
    {
        $this->tables();

        $result = (new SchemaSource(new Catalog(['orders' => ['id' => 'public']]), [Order::class]))
            ->read(new SourceRequest(['model' => Order::class]));

        $this->assertSame([['name' => 'user', 'model' => 'hidden', 'type' => 'BelongsTo']], $result->data['relations']);
    }

    public function test_the_class_of_a_related_model_on_the_list_is_shown(): void
    {
        $this->tables();

        $result = (new SchemaSource(new Catalog(['orders' => ['id' => 'public']]), [Order::class, User::class]))
            ->read(new SourceRequest(['model' => Order::class]));

        $this->assertSame(User::class, $result->data['relations'][0]['model']);
    }

    public function test_a_relation_method_that_throws_is_skipped(): void
    {
        $this->tables();
        $model = new class () extends Model {
            protected $table = 'orders';

            /** @return HasMany<Model, $this> */
            public function broken(): HasMany
            {
                throw new RuntimeException('needs a connection');
            }
        };

        $result = (new SchemaSource(new Catalog(['orders' => ['id' => 'public']]), [$model::class]))
            ->read(new SourceRequest(['model' => $model::class]));

        $this->assertTrue($result->ok);
        $this->assertSame([], $result->data['relations']);
    }

    public function test_the_column_type_is_the_type_name_without_enum_values(): void
    {
        $model = new class () extends Order {
            public static Connection $stub;

            public function getConnection(): Connection
            {
                return self::$stub;
            }
        };
        $model::$stub = new StubSchemaConnection([
            ['name' => 'status', 'type_name' => 'enum', 'type' => "enum('draft','paid')"],
        ]);

        $result = (new SchemaSource(new Catalog(['orders' => ['status' => 'public']]), [$model::class]))
            ->read(new SourceRequest(['model' => $model::class]));

        $this->assertSame('enum', $result->data['columns'][0]['type']);
        $this->assertStringNotContainsString('draft', (string) json_encode($result->data));
    }

    private function tables(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
        });
    }
}
