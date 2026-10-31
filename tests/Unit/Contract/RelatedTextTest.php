<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Contract;

use ContextGate\Classification\Catalog;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\ContractValidator;
use ContextGate\Contract\Field;
use ContextGate\Tests\Fixtures\CustomerRecord;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Related rows are not normalised, redacted or quarantined, so a text field on a related contract fails to load
 * (fail closed). Text fields on the parent contract stay allowed.
 */
class RelatedTextTest extends TestCase
{
    public function test_a_text_field_on_a_related_contract_fails_to_load(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contract.related_text');

        (new ContractValidator())->validate(new RelatedTextParentContract(), $this->catalog());
    }

    public function test_a_text_field_on_the_parent_contract_loads(): void
    {
        (new ContractValidator())->validate(new ParentTextContract(), $this->catalog());

        $this->expectNotToPerformAssertions();
    }

    private function catalog(): Catalog
    {
        return new Catalog([
            'related_orders' => ['customer_id' => 'internal', 'note' => 'internal'],
            'customers' => ['name' => 'public', 'bio' => 'internal'],
        ]);
    }
}

class RelatedTextParentContract extends ContextContract
{
    public function fields(): array
    {
        return ['customer_id' => Field::relation(RelatedBioContract::class)];
    }

    public function model(): string
    {
        return RelatedTextOrderModel::class;
    }
}

class ParentTextContract extends ContextContract
{
    public function fields(): array
    {
        return ['note' => Field::text()];
    }

    public function model(): string
    {
        return RelatedTextOrderModel::class;
    }
}

class RelatedBioContract extends ContextContract
{
    public function fields(): array
    {
        return ['name' => Field::plain(), 'bio' => Field::text()];
    }

    public function model(): string
    {
        return CustomerRecord::class;
    }
}

class RelatedTextOrderModel extends Model
{
    protected $table = 'related_orders';
}
