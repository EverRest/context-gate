<?php

declare(strict_types=1);

namespace ContextGate\Source\Pseudo;

use Illuminate\Database\Eloquent\Model;

/** Pseudo-table of openapi.endpoints row. Never queried: the catalog classifies its columns, and no row is read from it. */
final class SourceOpenApiEndpointRow extends Model
{
    protected $table = 'source_openapi_endpoints';
}
