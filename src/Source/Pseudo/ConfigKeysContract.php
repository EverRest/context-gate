<?php

declare(strict_types=1);

namespace ContextGate\Source\Pseudo;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;

/** Contract of the source rows; the fields are the output columns, each classified in the catalog under source_config_keys. */
final class ConfigKeysContract extends ContextContract
{
    public function fields(): array
    {
        return [
            'key' => Field::plain(),
            'type' => Field::plain(),
            'env' => Field::plain(),
        ];
    }

    public function model(): string
    {
        return SourceConfigKeysRow::class;
    }
}
