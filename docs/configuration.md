# Configuration

Every key below is in `config/context-gate.php`, and every key in that file is listed here. Defaults are the values
in the published file. A limit set to `null` uses the code default; a value you set can only lower a limit, and a
value above its code cap is ignored and reported by `php artisan gate:check`.

Examples use fake values only.

## `enabled`

Default: `false`. Turns the gate on. Nothing is served until this is `true` and at least one tool is listed under
`tools`. `gate:install` leaves it `false`.

## `profile`

Default: `'strict'`. The security profile: `strict` or `balanced`. Both select values inside the code caps and neither
changes the pipeline stages. `APP_ENV=production` always uses `strict`, and `gate:check` fails a weaker profile there.

## `callers`

Default: `[]`. Tools each caller may see and call, keyed by caller id, for example `'alice' => ['tools' => ['customer_lookup']]`.
A tool not listed for a caller is hidden from that caller. Each listed name must be a key of `tools`.

## `log_level`

Default: `'info'`. One of `debug`, `info`, `notice`, `warning`, `error`. Validated only; receipts are not affected.

## `tools`

Default: `[]`. The tools a caller may see and call, keyed by tool name. A tool entry names its class, its contract,
its risk and its enabled flag, for example:

```php
'tools' => [
    'customer_lookup' => [
        'enabled' => true,
        'class' => App\Gate\CustomerLookupTool::class,
        'contract' => App\Gate\CustomerContract::class,
        'risk' => 'low',
        'fingerprint' => 'FAKE_FIXTURE_ONLY',
    ],
],
```

Disabled tools are listed by name only. `php artisan gate:fingerprint customer_lookup` prints the description
fingerprint to paste into `fingerprint`. `php artisan gate:audit` lists the enabled tools with their ceilings, field
classes and rule hashes, without values.

## `canaries.values`

Default: `[]`. Canary values. A response or serialized output that contains one of them (case-insensitive, after
text normalisation) is blocked. Set them from your environment in the published file, and keep them out of version
control. Example with a fake value: `'values' => ['FAKE_FIXTURE_ONLY_canary']`. Empty values are ignored.

## `firewall.ceiling`

Default: `'internal'`. The most sensitive class the stdio caller may receive: `public`, `internal` or `personal`.
Values outside that set fail `gate:check`. `secret` is never released to a caller. HTTP callers get their ceiling
from their token instead (see [tokens.md](tokens.md)).

## `firewall.limits.per_call_bytes`

Default: `null` (code default 16384; cap 65536). Largest response the gate returns for one call. Longer responses are
truncated.

## `firewall.limits.per_session_bytes`

Default: `null` (code default 409600; cap 2097152). Bytes returned per stdio session before further calls are refused.

## `firewall.limits.per_day_bytes`

Default: `null` (code default 5242880; cap 20971520). Bytes returned per token per day before further calls are
refused.

## `firewall.limits.calls_per_minute`

Default: `null` (code default 60; cap 300). Calls allowed per minute for one caller. Exceeding it refuses the call.

## `classification`

Default: the source pseudo-tables (`source_routes`, `source_config_keys`, `source_schema_items`, `source_docs`,
`source_openapi_endpoints`), with every column `internal`. This is a map of table, then column, then class
(`public`, `internal`, `personal` or `secret`). A column that is not listed is unclassified and is dropped from
responses. Publishing the file replaces the whole map, so keep the source entries when you add your own tables.

## `http.path`

Default: `'mcp/context-gate'`. The route of the MCP endpoint over HTTP. Requests must carry a bearer token.

## `http.origins`

Default: `[]`. Exact `Origin` values (scheme, host and port) that a browser may send, for example
`'https://app.example.test'`. An empty list refuses every browser `Origin`. Requests without an `Origin` header are
non-browser clients and are accepted. See [tokens.md](tokens.md#the-origin-rule).

## `channel`

Default: `'context-gate'`. The log channel that receives one receipt line per call. Receipts go to stderr only and
hold metadata and hashes, never values.

## `sources.routes.exclude`

Default: `[]`. Route patterns to hide from the routes source. The framework debug routes stay excluded in code.

## `sources.eloquent.models`

Default: `[]`. Models the schema source may describe. A model not listed is not described.

## `sources.config.env_map`

Default: `[]`. Maps a config key to the environment name it reads, for the config source. For example
`'services.example.key' => 'EXAMPLE_KEY'`. The source reports names and types, never values.

## `sources.docs.paths`

Default: `[]`. Roots, relative to the application root, that the docs source may read. Nothing outside these roots is
readable.

## `sources.openapi.paths`

Default: `[]`. Roots that the OpenAPI source may read. Only `.json`, `.yaml` and `.yml` files are read.

## `sources.openapi.allowlist`

Default: `[]`. Hosts an OpenAPI URL source may read from. An empty list refuses every URL.

## `sources.openapi.access`

Default: `'read'`. The only accepted value. Connector write access is not available; any other value fails `gate:check`.

## `sources.openapi.timeout_seconds`

Default: `null` (code cap 5.0). Fetch timeout for an OpenAPI URL. A value above the cap is reported by `gate:check`.

## `sources.openapi.max_bytes`

Default: `null` (code cap 2097152). Largest OpenAPI document read. A value above the cap is reported by `gate:check`.

## `sources.openapi.examples`

Default: `drop`. `drop` removes example values from the OpenAPI document. `redacted` keeps them and passes each value
through redaction, the canary check and quarantine. Any other value is treated as `drop`, and `gate:check` fails.

## Commands that read this file

- `php artisan gate:install`: publishes the file.
- `php artisan gate:check`: validates it; exit `0` only when no check fails.
- `php artisan gate:audit`: reports the enabled tools it configures.
- `php artisan gate:fingerprint <tool>`: prints a tool's description fingerprint.
