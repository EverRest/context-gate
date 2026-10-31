<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;

class RelatedProbeContract extends ContextContract
{
    public function fields(): array
    {
        return ['name' => Field::plain()];
    }

    public function model(): string
    {
        return CustomerRecord::class;
    }
}
