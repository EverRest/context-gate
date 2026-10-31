<?php

declare(strict_types=1);

namespace ContextGate\Source\Pseudo;

use Illuminate\Database\Eloquent\Model;

/** Pseudo-table of config.keys rows. Never queried: the catalog classifies its columns, and no row is read from it. */
final class SourceConfigKeysRow extends Model
{
    protected $table = 'source_config_keys';
}
