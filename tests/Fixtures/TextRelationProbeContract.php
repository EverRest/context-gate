<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;

/** Test-only: relates to a contract with a text field, which a contract may not do (contract.related_text). */
class TextRelationProbeContract extends ContextContract
{
    public function fields(): array
    {
        return ['customer_id' => Field::relation(CustomerContract::class)];
    }

    public function model(): string
    {
        return ProbeOrderModel::class;
    }
}
