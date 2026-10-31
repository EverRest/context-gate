<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Contract;

use ContextGate\Classification\Catalog;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\ContractValidator;
use ContextGate\Contract\Field;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** User Story 2, scenarios 2 and 3 and FR-006: a personal field loads only as a pseudonym, aggregate or bucket. */
class PersonalFormTest extends TestCase
{
    public function test_a_personal_field_with_a_plain_form_fails_to_load_and_names_the_field_and_the_rule(): void
    {
        $catalog = new Catalog(['personal_users' => ['email' => 'personal']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('personal_users.email');
        $this->expectExceptionMessage('personal');
        $this->expectExceptionMessage('FR-006');

        (new ContractValidator())->validate(new PersonalPlainContract(), $catalog);
    }

    public function test_a_personal_field_as_a_pseudonym_loads(): void
    {
        $catalog = new Catalog(['personal_users' => ['email' => 'personal']]);

        (new ContractValidator())->validate(new PersonalPseudonymContract(), $catalog);

        $this->expectNotToPerformAssertions();
    }

    public function test_a_personal_field_as_an_aggregate_loads(): void
    {
        $catalog = new Catalog(['personal_users' => ['email' => 'personal']]);

        (new ContractValidator())->validate(new PersonalAggregateContract(), $catalog);

        $this->expectNotToPerformAssertions();
    }

    public function test_a_personal_field_as_a_bucket_loads(): void
    {
        $catalog = new Catalog(['personal_users' => ['email' => 'personal']]);

        (new ContractValidator())->validate(new PersonalBucketContract(), $catalog);

        $this->expectNotToPerformAssertions();
    }
}

class PersonalUserRecord extends Model
{
    protected $table = 'personal_users';
}

class PersonalPlainContract extends ContextContract
{
    public function fields(): array
    {
        return ['email' => Field::plain()];
    }

    public function model(): string
    {
        return PersonalUserRecord::class;
    }
}

class PersonalPseudonymContract extends ContextContract
{
    public function fields(): array
    {
        return ['email' => Field::pseudonym('USR')];
    }

    public function model(): string
    {
        return PersonalUserRecord::class;
    }
}

class PersonalAggregateContract extends ContextContract
{
    public function fields(): array
    {
        return ['email' => Field::aggregate()];
    }

    public function model(): string
    {
        return PersonalUserRecord::class;
    }
}

class PersonalBucketContract extends ContextContract
{
    public function fields(): array
    {
        return ['email' => Field::bucket([0, 10])];
    }

    public function model(): string
    {
        return PersonalUserRecord::class;
    }
}
