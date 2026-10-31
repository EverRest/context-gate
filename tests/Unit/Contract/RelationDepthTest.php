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

/** FR-011 and User Story 4, scenario 2: a related contract with its own relation fails to load. */
class RelationDepthTest extends TestCase
{
    public function test_a_relation_to_a_contract_without_relations_loads(): void
    {
        $catalog = $this->catalog();

        (new ContractValidator())->validate(new DepthOrderFlatContract(), $catalog);

        $this->expectNotToPerformAssertions();
    }

    public function test_a_relation_to_a_contract_that_has_its_own_relation_fails_with_contract_depth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contract.depth');

        (new ContractValidator())->validate(new DepthOrderDeepContract(), $this->catalog());
    }

    private function catalog(): Catalog
    {
        return new Catalog([
            'depth_orders' => ['customer_id' => 'internal'],
            'depth_customers' => ['email' => 'internal', 'address_id' => 'internal'],
            'depth_addresses' => ['city' => 'internal'],
        ]);
    }
}

class DepthOrderRecord extends Model
{
    protected $table = 'depth_orders';
}

class DepthCustomerRecord extends Model
{
    protected $table = 'depth_customers';
}

class DepthAddressRecord extends Model
{
    protected $table = 'depth_addresses';
}

class DepthAddressContract extends ContextContract
{
    public function fields(): array
    {
        return ['city' => Field::plain()];
    }

    public function model(): string
    {
        return DepthAddressRecord::class;
    }
}

class DepthCustomerContract extends ContextContract
{
    public function fields(): array
    {
        return ['email' => Field::plain(), 'address_id' => Field::relation(DepthAddressContract::class)];
    }

    public function model(): string
    {
        return DepthCustomerRecord::class;
    }
}

class DepthFlatCustomerContract extends ContextContract
{
    public function fields(): array
    {
        return ['email' => Field::plain()];
    }

    public function model(): string
    {
        return DepthCustomerRecord::class;
    }
}

class DepthOrderFlatContract extends ContextContract
{
    public function fields(): array
    {
        return ['customer_id' => Field::relation(DepthFlatCustomerContract::class)];
    }

    public function model(): string
    {
        return DepthOrderRecord::class;
    }
}

class DepthOrderDeepContract extends ContextContract
{
    public function fields(): array
    {
        return ['customer_id' => Field::relation(DepthCustomerContract::class)];
    }

    public function model(): string
    {
        return DepthOrderRecord::class;
    }
}
