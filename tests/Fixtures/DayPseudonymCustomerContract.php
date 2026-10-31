<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;

class DayPseudonymCustomerContract extends ContextContract
{
    public function fields(): array
    {
        return ['name' => Field::pseudonym('USR', 'day')];
    }

    public function model(): string
    {
        return CustomerRecord::class;
    }
}
