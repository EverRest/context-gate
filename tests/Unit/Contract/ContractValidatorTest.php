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

class ContractValidatorTest extends TestCase
{
    public function test_a_contract_whose_columns_are_all_classified_loads(): void
    {
        $catalog = new Catalog(['orders' => ['status' => 'public', 'total' => 'internal']]);

        (new ContractValidator())->validate(new OrderStatusContract(), $catalog);

        $this->expectNotToPerformAssertions();
    }

    public function test_a_contract_referring_to_an_unclassified_column_fails_and_names_the_column(): void
    {
        $catalog = new Catalog(['orders' => ['status' => 'public']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('total');

        (new ContractValidator())->validate(new OrderStatusContract(), $catalog);
    }
}

class OrderRecord extends Model
{
    protected $table = 'orders';
}

class OrderStatusContract extends ContextContract
{
    public function fields(): array
    {
        return ['status' => Field::plain(), 'total' => Field::plain()];
    }

    public function model(): string
    {
        return OrderRecord::class;
    }
}
