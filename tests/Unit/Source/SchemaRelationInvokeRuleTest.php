<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Source;

use ContextGate\Classification\Catalog;
use ContextGate\Source\SchemaSource;
use ContextGate\Source\SourceRequest;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait SideEffectRelationTrait
{
    /** @return HasMany<Model, $this> */
    public function fromTrait(): HasMany
    {
        SchemaRelationInvokeRuleTest::$calls[] = 'fromTrait';

        return $this->hasMany(static::class);
    }
}

class SchemaRelationInvokeModel extends Model
{
    use SideEffectRelationTrait;

    protected $table = 'orders';

    /** @return HasMany<Model, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class);
    }

    public function sideEffect(): mixed
    {
        SchemaRelationInvokeRuleTest::$calls[] = 'sideEffect';

        return $this->hasMany(self::class);
    }
}

/** Only methods declared on the model itself with an explicit Relation return type are invoked. */
class SchemaRelationInvokeRuleTest extends TestCase
{
    /** @var list<string> */
    public static array $calls = [];

    public function test_only_own_typed_relation_methods_are_invoked(): void
    {
        self::$calls = [];
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
        });

        $result = (new SchemaSource(new Catalog(['orders' => ['id' => 'public']]), [SchemaRelationInvokeModel::class]))
            ->read(new SourceRequest(['model' => SchemaRelationInvokeModel::class]));

        $this->assertSame(['children'], array_column($result->data['relations'], 'name'));
        $this->assertSame([], self::$calls);
    }
}
