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
 * A related row is keyed by the session scope only, so a related day or week pseudonym cannot be issued. The
 * contract fails to load, rather than the call being refused at runtime.
 */
class RelatedPseudonymScopeTest extends TestCase
{
    public function test_a_related_day_pseudonym_fails_to_load(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contract.related_pseudonym_scope');

        (new ContractValidator())->validate(new ScopeDayParentContract(), $this->catalog());
    }

    public function test_a_related_session_pseudonym_loads(): void
    {
        (new ContractValidator())->validate(new ScopeSessionParentContract(), $this->catalog());

        $this->expectNotToPerformAssertions();
    }

    private function catalog(): Catalog
    {
        return new Catalog([
            'scope_orders' => ['customer_id' => 'internal'],
            'customers' => ['email' => 'personal'],
        ]);
    }
}

class ScopeDayParentContract extends ContextContract
{
    public function fields(): array
    {
        return ['customer_id' => Field::relation(ScopeDayCustomerContract::class)];
    }

    public function model(): string
    {
        return ScopeOrderModel::class;
    }
}

class ScopeSessionParentContract extends ContextContract
{
    public function fields(): array
    {
        return ['customer_id' => Field::relation(ScopeSessionCustomerContract::class)];
    }

    public function model(): string
    {
        return ScopeOrderModel::class;
    }
}

class ScopeDayCustomerContract extends ContextContract
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

class ScopeSessionCustomerContract extends ContextContract
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

class ScopeOrderModel extends Model
{
    protected $table = 'scope_orders';
}
