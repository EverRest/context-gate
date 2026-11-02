<?php

declare(strict_types=1);

namespace ContextGate\Settings;

/**
 * The classes of the package's own source tables. They live in code, so a published config file cannot
 * reclassify them; a host may only hide a table or a column of one (ClassificationMerge).
 *
 * @internal May change without notice.
 */
final class BuiltinClassification
{
    /** @var array<string, array<string, string>> table => column => class */
    public const TABLES = [
        'source_routes' => [
            'method' => 'internal', 'uri' => 'internal', 'name' => 'internal',
            'controller' => 'internal', 'rule_names' => 'internal', 'middleware' => 'internal',
        ],
        'source_config_keys' => ['key' => 'internal', 'type' => 'internal', 'env' => 'internal'],
        'source_schema_items' => [
            'model' => 'internal', 'table' => 'internal', 'kind' => 'internal', 'name' => 'internal',
            'type' => 'internal', 'class' => 'internal', 'detail' => 'internal',
        ],
        'source_docs' => ['source' => 'internal', 'text' => 'internal', 'truncated' => 'internal'],
        'source_runbook_rows' => ['step' => 'internal', 'status' => 'internal', 'text' => 'internal'],
        'source_openapi_endpoints' => [
            'version' => 'internal', 'method' => 'internal', 'path' => 'internal', 'parameters' => 'internal',
            'request_schema' => 'internal', 'examples' => 'internal', 'response_schemas' => 'internal', 'security' => 'internal',
        ],
    ];
}
