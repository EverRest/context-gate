<?php

declare(strict_types=1);

namespace ContextGate\Source\Pseudo;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;

/** Contract of the source rows; the fields are the output columns, each classified in the catalog under source_routes. */
final class RoutesContract extends ContextContract
{
    public function fields(): array
    {
        return [
            'method' => Field::plain(),
            'uri' => Field::plain(),
            'name' => Field::plain(),
            'controller' => Field::plain(),
            'rule_names' => Field::plain(),
            'middleware' => Field::plain(),
        ];
    }

    public function model(): string
    {
        return SourceRoutesRow::class;
    }
}
