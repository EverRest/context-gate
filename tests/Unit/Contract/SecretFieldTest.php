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

/** User Story 2, scenario 1 and FR-005: a contract that lists a secret column never loads. */
class SecretFieldTest extends TestCase
{
    public function test_a_contract_listing_a_secret_column_fails_to_load_and_names_the_field_and_the_rule(): void
    {
        $catalog = new Catalog(['secret_users' => ['name' => 'public', 'password' => 'secret']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('secret_users.password');
        $this->expectExceptionMessage('secret');
        $this->expectExceptionMessage('FR-005');

        (new ContractValidator())->validate(new SecretUserContract(), $catalog);
    }
}

class SecretUserRecord extends Model
{
    protected $table = 'secret_users';
}

class SecretUserContract extends ContextContract
{
    public function fields(): array
    {
        return ['name' => Field::plain(), 'password' => Field::aggregate()];
    }

    public function model(): string
    {
        return SecretUserRecord::class;
    }
}
