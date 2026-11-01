<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;

/** Invalid on purpose: a personal column listed in the plain form. */
class PlainPersonalContract extends ContextContract
{
    public function fields(): array
    {
        return ['status' => Field::plain(), 'email' => Field::plain()];
    }

    public function model(): string
    {
        return CustomerRecord::class;
    }
}
