<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

require_once __DIR__ . '/../Fixtures/app/app/Models/User.php';

require_once __DIR__ . '/../Fixtures/app/app/Models/Order.php';

use App\Models\Order;
use App\Models\User;
use ContextGate\Classification\Catalog;
use ContextGate\Source\SchemaSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SchemaRelationTest extends TestCase
{
    public function test_relation_is_listed_and_the_related_secret_column_stays_hidden(): void
    {
        $this->createTables();
        $catalog = new Catalog([
            'users' => ['id' => 'public', 'name' => 'personal', 'email' => 'personal', 'password' => 'secret'],
            'orders' => ['id' => 'public', 'user_id' => 'internal', 'total' => 'internal'],
        ]);

        $result = (new SchemaSource($catalog, [Order::class]))->read(new SourceRequest(['model' => Order::class]));

        $this->assertTrue($result->ok);
        // User is not on the configured list, so the related class is hidden (F4: the list decides what is shown).
        $this->assertSame([['name' => 'user', 'model' => 'hidden', 'type' => 'BelongsTo']], $result->data['relations']);
        $this->assertStringNotContainsString('password', json_encode($result->data, JSON_THROW_ON_ERROR));
    }

    private function createTables(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->integer('total');
        });
    }
}
