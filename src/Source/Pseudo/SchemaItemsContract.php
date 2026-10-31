<?php

declare(strict_types=1);

namespace ContextGate\Source\Pseudo;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;

/** Contract of the source rows; the fields are the output columns, each classified in the catalog under source_schema_items. */
final class SchemaItemsContract extends ContextContract
{
    public function fields(): array
    {
        return [
            'model' => Field::plain(),
            'table' => Field::plain(),
            'kind' => Field::plain(),
            'name' => Field::plain(),
            'type' => Field::plain(),
            'class' => Field::plain(),
            'detail' => Field::plain(),
        ];
    }

    public function model(): string
    {
        return SourceSchemaItemsRow::class;
    }
}
