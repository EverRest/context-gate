<?php

declare(strict_types=1);

namespace ContextGate\Source\Pseudo;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;

/** Contract of the source rows; the fields are the output columns, each classified in the catalog under source_docs. */
final class DocsContract extends ContextContract
{
    public function fields(): array
    {
        return [
            'source' => Field::plain(),
            'text' => Field::text(),
            'truncated' => Field::plain(),
        ];
    }

    public function model(): string
    {
        return SourceDocsRow::class;
    }
}
