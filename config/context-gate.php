<?php

declare(strict_types=1);

return [

    /*
    | Off until `gate:install` is run and tools are listed here. Nothing is visible by default.
    */
    'enabled' => false,

    /*
    | Tools a caller may see and call, keyed by tool name. Empty by default.
    */
    'tools' => [],

    /*
    | Canary values: a response or serialized output containing one of them is blocked (FR-017). Set by the
    | operator from the environment; empty by default.
    */
    'canaries' => ['values' => []],

    /*
    | The most sensitive class the stdio caller may receive (stdio only; tokens supply it in spec 005).
    */
    'firewall' => [
        'ceiling' => 'internal',

        /*
        | Null keeps the code default. A value lowers that limit; a value above its code cap is ignored and
        | gate:check reports it.
        */
        'limits' => [
            'per_call_bytes' => null,
            'per_session_bytes' => null,
            'per_day_bytes' => null,
            'calls_per_minute' => null,
        ],
    ],

    /*
    | Classification catalog: table => column => class. Unlisted columns are unclassified and dropped. The source
    | tables (spec 003) are the pseudo-tables of the four source tools; their columns are internal by default.
    | Publishing this file replaces the whole map, so keep these entries when adding your own.
    */
    'classification' => [
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
        'source_openapi_endpoints' => [
            'version' => 'internal', 'method' => 'internal', 'path' => 'internal', 'parameters' => 'internal',
            'request_schema' => 'internal', 'examples' => 'internal', 'response_schemas' => 'internal', 'security' => 'internal',
        ],
    ],

    /*
    | HTTP transport (spec 005). The path is the MCP route; origins are exact Origin values a browser may send
    | (scheme, host and port). Empty refuses every Origin header; requests without one are accepted.
    */
    'http' => [
        'path' => 'mcp/context-gate',
        'origins' => [],
    ],

    /*
    | Log channel that receives one receipt line per call. Writes to stderr only.
    */
    'channel' => 'context-gate',

    /*
    | Laravel sources (spec 003). Each list is empty by default, so a source shows nothing until the operator names it.
    | routes.exclude adds route patterns; the framework debug routes stay excluded in code.
    | eloquent.models lists the models schema.describe may describe.
    | config.env_map maps a config key to the env name it reads, for config.keys.
    | docs.paths lists the roots docs.read may read, relative to the application root.
    | openapi.paths lists the roots openapi.endpoints may read (.json, .yaml, .yml files only).
    */
    'sources' => [
        'routes' => ['exclude' => []],
        'eloquent' => ['models' => []],
        'config' => ['env_map' => []],
        'docs' => ['paths' => []],
        // openapi.allowlist lists the hosts an OpenAPI URL source may read from; empty refuses every URL.
        // openapi.examples: 'drop' (default) removes example values; 'redacted' keeps them, and the firewall
        // redacts, canary-checks and quarantines them. Any other value drops them and gate:check fails.
        'openapi' => ['paths' => [], 'allowlist' => [], 'examples' => 'drop'],
    ],

];
