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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** F4, research R3: one broken relation is skipped; a related model off the configured list shows as hidden. */
class SchemaRelationGuardTest extends TestCase
{
    public function test_a_relation_method_that_throws_is_skipped_and_the_call_succeeds(): void
    {
        $this->createTables();

        $result = $this->describe(SchemaBrokenRelationOrder::class, [SchemaBrokenRelationOrder::class]);

        $this->assertTrue($result->ok);
        $this->assertSame(['user'], array_column($result->data['relations'], 'name'));
    }

    public function test_a_related_model_off_the_list_is_shown_as_hidden(): void
    {
        $this->createTables();

        $result = $this->describe(Order::class, [Order::class]);

        $this->assertTrue($result->ok);
        $this->assertSame([['name' => 'user', 'model' => 'hidden', 'type' => 'BelongsTo']], $result->data['relations']);
        $this->assertStringNotContainsString(User::class, (string) json_encode($result->data, JSON_THROW_ON_ERROR));
    }

    public function test_a_related_model_on_the_list_is_shown_by_class(): void
    {
        $this->createTables();

        $result = $this->describe(Order::class, [Order::class, User::class]);

        $this->assertSame([['name' => 'user', 'model' => User::class, 'type' => 'BelongsTo']], $result->data['relations']);
    }

    /** @param list<class-string<Model>> $models */
    private function describe(string $model, array $models): \ContextGate\Source\SourceResult
    {
        return (new SchemaSource(new Catalog([]), $models))->read(new SourceRequest(['model' => $model]));
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

final class SchemaBrokenRelationOrder extends Model
{
    protected $table = 'orders';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Model, $this> */
    public function broken(): HasMany
    {
        throw new RuntimeException('SENTINEL_RELATION_DETAIL');
    }
}
