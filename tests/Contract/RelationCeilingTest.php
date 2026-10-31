<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Classification\Catalog;
use ContextGate\Classification\Ceiling;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;
use ContextGate\Pipeline\Payload;
use ContextGate\Pipeline\Relations;
use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Tests\Fixtures\CustomerRecord;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** User Story 4, scenario 3: with ceiling internal, a personal field of a related record is a pseudonym only. */
class RelationCeilingTest extends TestCase
{
    public function test_a_personal_field_of_the_related_record_appears_only_as_a_pseudonym_under_an_internal_ceiling(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
        });
        DB::table('customers')->insert(['id' => 1, 'email' => 'a@example.invalid']);

        $stage = new Relations(
            ['customer_id' => Field::relation(PseudonymCustomerContract::class)],
            new Catalog(['customers' => ['id' => 'public', 'email' => 'personal']]),
            Ceiling::Internal,
            new Pseudonymizer(),
            'session:'.uniqid('', true),
        );

        $result = $stage->handle(
            new Payload([['id' => 10, 'customer_id' => 1]], ['id', 'customer_id']),
            static fn (Payload $next): Payload => $next,
        );

        $this->assertMatchesRegularExpression('/^USR_[0-9a-f]{10}$/', $result->rows[0]['customer_id']['email']);
        $this->assertStringNotContainsString('a@example.invalid', (string) json_encode($result->rows));
    }
}

class PseudonymCustomerContract extends ContextContract
{
    public function fields(): array
    {
        return ['email' => Field::pseudonym('USR')];
    }

    public function model(): string
    {
        return CustomerRecord::class;
    }
}
