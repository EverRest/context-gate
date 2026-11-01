<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures\Quickstart\Models;

use Illuminate\Database\Eloquent\Model;

/** Quickstart fixture (spec 004): the orders table. Test data only. */
class Order extends Model
{
    protected $table = 'orders';
}
