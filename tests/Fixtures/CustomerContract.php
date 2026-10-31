<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;

/** Test-only contract for customer_lookup: customer and email are the only fields a caller may receive; email is text so redaction runs on it. */
class CustomerContract extends ContextContract
{
    public function fields(): array
    {
        return ['customer' => Field::plain(), 'email' => Field::text()];
    }

    public function model(): string
    {
        return CustomerRecord::class;
    }
}
