<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

require_once __DIR__ . '/../Fixtures/app/app/Models/User.php';

use App\Models\User;
use ContextGate\Classification\Catalog;
use ContextGate\Classification\Ceiling;
use ContextGate\Source\SchemaSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SchemaSecretRemovedTest extends TestCase
{
    public function test_secret_column_is_absent_and_counted(): void
    {
        $this->createTables();
        $catalog = new Catalog([
            'users' => ['id' => 'public', 'name' => 'personal', 'email' => 'personal', 'password' => 'secret'],
        ]);

        $result = (new SchemaSource($catalog, [User::class]))->read(new SourceRequest(['model' => User::class]));

        $this->assertTrue($result->ok);
        $this->assertSame(1, $result->data['hidden_columns']);
        $this->assertSame('users', $result->data['table']);
        $this->assertStringNotContainsString('password', json_encode($result->data, JSON_THROW_ON_ERROR));

        $columns = [];
        foreach ($result->data['columns'] as $column) {
            $columns[$column['name']] = $column['class'];
        }

        $this->assertSame(['id' => 'public', 'name' => 'personal', 'email' => 'personal'], $columns);
        $this->assertSame(Ceiling::Personal->value, $columns['email']);
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
