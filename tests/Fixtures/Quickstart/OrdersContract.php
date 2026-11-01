<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures\Quickstart;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;
use ContextGate\Tests\Fixtures\Quickstart\Models\Order;

/** Quickstart fixture (spec 004 section 4): the fields orders.status may return. notes has no catalog entry, so it is dropped. */
class OrdersContract extends ContextContract
{
    public function fields(): array
    {
        return ['id' => Field::plain(), 'status' => Field::plain(), 'total' => Field::plain(), 'notes' => Field::plain()];
    }

    public function model(): string
    {
        return Order::class;
    }
}
