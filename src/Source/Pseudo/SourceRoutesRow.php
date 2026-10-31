<?php

declare(strict_types=1);

namespace ContextGate\Source\Pseudo;

use Illuminate\Database\Eloquent\Model;

/** Pseudo-table of routes.list rows (contracts/sources.md). Never queried: the catalog classifies its columns, and no row is read from it. */
final class SourceRoutesRow extends Model
{
    protected $table = 'source_routes';
}
