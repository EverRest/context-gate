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
use ContextGate\Tests\Fixtures\CustomerRecord;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** FR-012: related rows are loaded in one query per relation and counted with the parent rows. */
class RelationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
        });
        DB::table('customers')->insert([
            ['id' => 1, 'email' => 'a@example.invalid'],
            ['id' => 2, 'email' => 'b@example.invalid'],
        ]);
    }

    public function test_related_rows_are_loaded_in_one_query_per_relation(): void
    {
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries++;
        });

        $result = $this->relate(
            [['id' => 10, 'customer_id' => 1], ['id' => 11, 'customer_id' => 2], ['id' => 12, 'customer_id' => 1]],
            ['customers' => ['id' => 'public', 'email' => 'internal']],
            RelatedCustomerContract::class,
            Ceiling::Internal,
        );

        $this->assertSame(1, $queries);
        $this->assertSame(['email' => 'a@example.invalid'], $result->rows[0]['customer_id']);
        $this->assertSame(['email' => 'b@example.invalid'], $result->rows[1]['customer_id']);
        $this->assertSame(['email' => 'a@example.invalid'], $result->rows[2]['customer_id']);
        $this->assertCount(3, $result->rows);
    }

    public function test_a_related_record_that_does_not_exist_is_null(): void
    {
        $result = $this->relate(
            [['id' => 10, 'customer_id' => 99]],
            ['customers' => ['id' => 'public', 'email' => 'internal']],
            RelatedCustomerContract::class,
            Ceiling::Internal,
        );

        $this->assertNull($result->rows[0]['customer_id']);
    }

    public function test_a_related_field_above_the_ceiling_is_dropped_and_recorded_with_its_relation_name(): void
    {
        $result = $this->relate(
            [['id' => 10, 'customer_id' => 1]],
            ['customers' => ['id' => 'public', 'email' => 'personal']],
            RelatedCustomerContract::class,
            Ceiling::Internal,
        );

        $this->assertSame([], $result->rows[0]['customer_id']);
        $this->assertSame([['field' => 'customer_id.email', 'reason' => 'ceiling.exceeded']], $result->dropped);
    }

    /** A day-scoped related pseudonym is issued for the caller that makes the call (R4, FR-009). */
    public function test_a_day_scoped_related_pseudonym_is_issued_for_the_calling_caller(): void
    {
        $result = $this->relateByDay([['id' => 10, 'customer_id' => 1]], 'alice');

        $expected = (new Pseudonymizer())->pseudonym('USR', 'customers', '1', 'day:probe', 'alice');
        $this->assertSame(['email' => $expected], $result->rows[0]['customer_id']);
        $this->assertSame([], $result->dropped);
    }

    /** A related pseudonym that cannot be issued drops the relation: its foreign key and field go, the rest stays. */
    public function test_a_related_pseudonym_that_cannot_be_issued_drops_the_relation_as_relation_failed(): void
    {
        $result = $this->relateByDay([['id' => 10, 'customer_id' => 1]], null);

        $this->assertArrayNotHasKey('customer_id', $result->rows[0]);
        $this->assertSame(['id'], $result->fields);
        $this->assertSame([['field' => 'customer_id', 'reason' => 'relation.failed']], $result->dropped);
    }

    /** @param list<array<string, mixed>> $rows */
    private function relateByDay(array $rows, ?string $caller): Payload
    {
        $stage = new Relations(
            ['customer_id' => Field::relation(RelatedDayCustomerContract::class)],
            new Catalog(['customers' => ['id' => 'public', 'email' => 'internal']]),
            Ceiling::Internal,
            new Pseudonymizer(),
            'day:probe',
            $caller,
        );

        return $stage->handle(
            new Payload($rows, ['id', 'customer_id']),
            static fn (Payload $next): Payload => $next,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, array<string, string>> $map
     * @param class-string<ContextContract> $contract
     */
    private function relate(array $rows, array $map, string $contract, Ceiling $ceiling): Payload
    {
        $stage = new Relations(
            ['customer_id' => Field::relation($contract)],
            new Catalog($map),
            $ceiling,
            new Pseudonymizer(),
            'session:'.uniqid('', true),
        );

        return $stage->handle(
            new Payload($rows, ['id', 'customer_id']),
            static fn (Payload $next): Payload => $next,
        );
    }
}

class RelatedCustomerContract extends ContextContract
{
    public function fields(): array
    {
        return ['email' => Field::plain()];
    }

    public function model(): string
    {
        return CustomerRecord::class;
    }
}

class RelatedDayCustomerContract extends ContextContract
{
    public function fields(): array
    {
        return ['email' => Field::pseudonym('USR', 'day')];
    }

    public function model(): string
    {
        return CustomerRecord::class;
    }
}
