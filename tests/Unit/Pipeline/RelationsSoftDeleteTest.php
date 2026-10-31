<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Pipeline;

use Carbon\CarbonImmutable;
use ContextGate\Classification\Catalog;
use ContextGate\Classification\Ceiling;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;
use ContextGate\Pipeline\Payload;
use ContextGate\Pipeline\Relations;
use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R3: a related model with soft deletes never loads its soft-deleted rows. */
class RelationsSoftDeleteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('soft_customers', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->timestamp('deleted_at')->nullable();
        });
        DB::table('soft_customers')->insert([
            ['id' => 1, 'email' => 'a@example.invalid', 'deleted_at' => null],
            ['id' => 2, 'email' => 'b@example.invalid', 'deleted_at' => CarbonImmutable::now()->toDateTimeString()],
        ]);
    }

    public function test_a_soft_deleted_related_row_is_not_returned(): void
    {
        $result = $this->relate(
            [['id' => 10, 'customer_id' => 1], ['id' => 11, 'customer_id' => 2]],
            RelatedSoftCustomerContract::class,
        );

        $this->assertSame(['email' => 'a@example.invalid'], $result->rows[0]['customer_id']);
        $this->assertNull($result->rows[1]['customer_id']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param class-string<ContextContract> $contract
     */
    private function relate(array $rows, string $contract): Payload
    {
        $stage = new Relations(
            ['customer_id' => Field::relation($contract)],
            new Catalog(['soft_customers' => ['id' => 'public', 'email' => 'internal']]),
            Ceiling::Internal,
            new Pseudonymizer(),
            'session:'.uniqid('', true),
        );

        return $stage->handle(
            new Payload($rows, ['id', 'customer_id']),
            static fn (Payload $next): Payload => $next,
        );
    }
}

class RelatedSoftCustomerContract extends ContextContract
{
    public function fields(): array
    {
        return ['email' => Field::plain()];
    }

    public function model(): string
    {
        return SoftCustomerRecord::class;
    }
}

class SoftCustomerRecord extends Model
{
    use SoftDeletes;

    protected $table = 'soft_customers';
}
