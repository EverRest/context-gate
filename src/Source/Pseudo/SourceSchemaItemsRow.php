<?php

declare(strict_types=1);

namespace ContextGate\Source\Pseudo;

use Illuminate\Database\Eloquent\Model;

/** Pseudo-table of schema.describe rows, one per model item. Never queried: the catalog classifies its columns, and no row is read from it. */
final class SourceSchemaItemsRow extends Model
{
    protected $table = 'source_schema_items';
}
