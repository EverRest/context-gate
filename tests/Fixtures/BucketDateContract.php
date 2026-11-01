<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;

/** Test-only contract with a bucket and a date column, to see a bad value in the transform stage. */
class BucketDateContract extends ContextContract
{
    public function fields(): array
    {
        return ['total' => Field::bucket([10, 100]), 'placed' => Field::date('month')];
    }

    public function model(): string
    {
        return CustomerRecord::class;
    }
}
