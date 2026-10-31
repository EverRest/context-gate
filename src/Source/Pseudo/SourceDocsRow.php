<?php

declare(strict_types=1);

namespace ContextGate\Source\Pseudo;

use Illuminate\Database\Eloquent\Model;

/** Pseudo-table of docs.read row. Never queried: the catalog classifies its columns, and no row is read from it. */
final class SourceDocsRow extends Model
{
    protected $table = 'source_docs';
}
