<?php

declare(strict_types=1);

namespace ContextGate\Runbook;

use Illuminate\Database\Eloquent\Model;

/** Pseudo-table of runbook rows (spec 018 FR-012). Never queried: the catalog classifies its columns. */
final class RunbookRow extends Model
{
    protected $table = 'source_runbook_rows';
}
