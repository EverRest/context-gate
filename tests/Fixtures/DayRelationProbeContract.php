<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;

class DayRelationProbeContract extends ContextContract
{
    public function fields(): array
    {
        return [
            'status' => Field::plain(),
            'customer_id' => Field::relation(DayPseudonymCustomerContract::class),
        ];
    }

    public function model(): string
    {
        return ProbeOrderModel::class;
    }
}
