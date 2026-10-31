<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;

class ProbeContract extends ContextContract
{
    public function fields(): array
    {
        return [
            'status' => Field::plain(),
            'note' => Field::text(),
            'email' => Field::pseudonym('USR'),
        ];
    }

    public function model(): string
    {
        return CustomerRecord::class;
    }
}
