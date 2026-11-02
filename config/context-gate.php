<?php

declare(strict_types=1);

return [

    /*
    | Off until `gate:install` is run and tools are listed here. Nothing is visible by default.
    */
    'enabled' => false,

    /*
    | Security profile: strict (default) or balanced. Both select values inside the code caps; neither can disable a
    | stage. APP_ENV=production always uses strict, and gate:check fails a weaker profile there (spec 007).
    */
    'profile' => 'strict',

    /*
    | Roles: what a kind of caller may do. Keyed by role name (letters, digits, _ and -). Each role lists its tools
    | (names in `tools`), a ceiling (public, internal or personal), and optional limits no higher than the profile.
    | HTTP tokens are issued with a role (gate:token --role); stdio_roles maps OS user names to roles.
    | Empty by default: no caller sees a tool until a role lists it here.
    */
    'roles' => [],

    /*
    | OS user name => role name, for the stdio transport. An OS user with no mapping has no role and sees no tools.
    */
    'stdio_roles' => [],

    /*
    | Log level for the context-gate channel. Validated only; it is not applied to receipts (spec 007, R6).
    */
    'log_level' => 'info',

    /*
    | Tools a caller may see and call, keyed by tool name. Empty by default.
    */
    'tools' => [],

    /*
    | Runbooks by name, mapped to their class (spec 018). Configuration maps names to classes only; it cannot change a
    | runbook's steps or raise a limit. Each name yields one tool, runbook.<name>, disabled until enabled in tools.
    */
    'runbooks' => [],

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
        | Null keeps the code default. A value lowers that limit; a value above its code cap refuses every call with
        | config.invalid, and gate:check reports it.
        */
        'limits' => [
            'per_call_bytes' => null,
            'per_session_bytes' => null,
            'per_day_bytes' => null,
            'calls_per_minute' => null,
        ],
    ],

    /*
    | Classification catalog: table => column => class (public, internal, personal or secret). Unlisted columns are
    | unclassified and dropped. Add your own tables here.
    |
    | The package's source tables (source_routes, source_config_keys, source_schema_items, source_docs,
    | source_runbook_rows, source_openapi_endpoints) are defined in code and merged in for you; leave them out. To hide
    | one, set it to false (`'source_docs' => false`); to hide one column, set that column to false
    | (`'source_routes' => ['middleware' => false]`). Any other change to a source table fails gate:check. The source_
    | prefix is reserved for the package.
    */
    'classification' => [],

    /*
    | HTTP transport (spec 005). The path is the MCP route; origins are exact Origin values a browser may send
    | (scheme, host and port). Empty refuses every Origin header; requests without one are accepted.
    | refusals_per_minute: refused requests (401/403) one client may cause per minute before it gets 429; null uses the
    | default 60, the cap is 600 and a configured value can only lower it.
    */
    'http' => [
        'path' => 'mcp/context-gate',
        'origins' => [],
        'refusals_per_minute' => null,
    ],

    /*
    | Log channel that receives one receipt line per call. Writes to stderr only.
    */
    'channel' => 'context-gate',

    /*
    | Cache store for budgets, rate limits and persistent pseudonym state. Null uses the host default store.
    | Use a store shared by every worker (redis, database); a per-process store resets limits per worker.
    */
    'cache_store' => null,

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
        // openapi.access is 'read' only: a write setting fails gate:check (spec 007, FR-009).
        // openapi.timeout_seconds and openapi.max_bytes are null for the profile value, or lower than their caps.
        'openapi' => [
            'paths' => [], 'allowlist' => [], 'examples' => 'drop',
            'access' => 'read', 'timeout_seconds' => null, 'max_bytes' => null,
        ],
    ],

];
