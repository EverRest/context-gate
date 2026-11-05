<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use ContextGate\Classification\Catalog;
use ContextGate\Classification\Ceiling;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;
use ContextGate\Pipeline\Payload;
use ContextGate\Pipeline\Relations;
use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A related record the host hides with a global scope (a tenant, an erasure flag) is not loaded: the relation reads
 * the rows the model itself would read, not the raw table.
 */
class RelationsGlobalScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('scoped_customers', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->unsignedInteger('tenant_id');
        });
        DB::table('scoped_customers')->insert([
            ['id' => 1, 'email' => 'own@example.invalid', 'tenant_id' => 7],
            ['id' => 2, 'email' => 'other@example.invalid', 'tenant_id' => 8],
        ]);
    }

    public function test_a_row_hidden_by_a_global_scope_is_not_returned(): void
    {
        $result = (new Relations(
            ['customer_id' => Field::relation(RelatedScopedCustomerContract::class)],
            new Catalog(['scoped_customers' => ['id' => 'public', 'email' => 'internal', 'tenant_id' => 'internal']]),
            Ceiling::Internal,
            new Pseudonymizer(),
            'session:'.uniqid('', true),
        ))->handle(
            new Payload([['id' => 10, 'customer_id' => 1], ['id' => 11, 'customer_id' => 2]], ['id', 'customer_id']),
            static fn (Payload $next): Payload => $next,
        );

        $this->assertSame(['email' => 'own@example.invalid'], $result->rows[0]['customer_id']);
        $this->assertNull($result->rows[1]['customer_id']);
    }

    public function test_the_related_values_stay_raw_attributes(): void
    {
        $result = (new Relations(
            ['customer_id' => Field::relation(RelatedScopedCustomerContract::class)],
            new Catalog(['scoped_customers' => ['id' => 'public', 'email' => 'internal', 'tenant_id' => 'internal']]),
            Ceiling::Internal,
            new Pseudonymizer(),
            'session:'.uniqid('', true),
        ))->handle(
            new Payload([['id' => 10, 'customer_id' => 1]], ['id', 'customer_id']),
            static fn (Payload $next): Payload => $next,
        );

        // The model's accessor upper-cases the email; the relation must not run it (no casts, no accessors).
        $this->assertSame(['email' => 'own@example.invalid'], $result->rows[0]['customer_id']);
    }
}

class RelatedScopedCustomerContract extends ContextContract
{
    public function fields(): array
    {
        return ['email' => Field::plain()];
    }

    public function model(): string
    {
        return ScopedCustomerRecord::class;
    }
}

class ScopedCustomerRecord extends Model
{
    protected $table = 'scoped_customers';

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', static fn (Builder $query): Builder => $query->where('tenant_id', 7));
    }

    public function getEmailAttribute(string $value): string
    {
        return strtoupper($value);
    }
}
