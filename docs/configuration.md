# Configuration

Every key below is in `config/context-gate.php`, and every key in that file is listed here. Defaults are the values
in the published file. A limit set to `null` uses the code default; a value you set can only lower a limit. A value
above its code cap refuses every call with `config.invalid`, and `php artisan gate:check` reports it. `gate:check` also verifies the PHP extensions curl, mbstring and intl.

Examples use fake values only.

## `enabled`

Default: `false`. Turns the gate on. Nothing is served until this is `true` and at least one tool is listed under
`tools`. While it is `false` every tool is hidden: `tools/list` is empty and `tools/call` answers `Tool not found.` like
an unknown tool (receipt `policy.hidden`); `gate:preview` reports the same. The HTTP route is registered either way
(unless routes are cached); the flag is read at request time. `gate:install` leaves it `false`.

## `profile`

Default: `'strict'`. The security profile: `strict` or `balanced`. Both select values inside the code caps and neither
changes the pipeline stages. `APP_ENV=production` always uses `strict`, and `gate:check` fails a weaker profile there.

## `callers`

Default: `[]`. Tools each caller may see and call, keyed by caller id, for example `'alice' => ['tools' => ['customer_lookup']]`.
Over stdio the caller id is the OS user name. Over HTTP it is `token:<id>`, for example
`'token:12' => ['tools' => ['customer_lookup']]`; `gate:token` prints the key when it issues a token and
`gate:token --list` has a `caller` column. A tool not listed for a caller is hidden from that caller. Each listed name
must be a key of `tools`.

## `log_level`

Default: `'info'`. One of `debug`, `info`, `notice`, `warning`, `error`. Validated only; receipts are not affected.

## `tools`

Default: `[]`. The tools a caller may see and call, keyed by tool name (`^[A-Za-z0-9_.-]{1,128}$`; boot fails
otherwise). A tool entry accepts only these keys: `enabled`, `class`, `gate`, `fingerprint` and `contract`. Any other
key is `config.invalid` and `gate:check` reports it. There is no per-tool byte or rate limit; the limits under
`firewall.limits` apply to every tool.

```php
'tools' => [
    'customer_lookup' => [
        'enabled' => true,
        'class' => App\Gate\CustomerLookupTool::class,
        'gate' => 'customer_lookup.allow',
        'contract' => App\Gate\CustomerContract::class,
        'fingerprint' => 'FAKE_FIXTURE_ONLY',
    ],
],
```

`gate` names a Laravel Gate ability. `ToolPolicy` denies a tool whose ability is not defined, so define it. The Gate
user is a `ContextGate\Server\Caller`:

```php
Gate::define('customer_lookup.allow', fn ($caller) => true);
```

Disabled tools are listed by name only. `php artisan gate:fingerprint customer_lookup` prints the fingerprint to paste
into `fingerprint`: the sha256 of the canonical JSON of the tool's name, description, input schema (an empty
`properties` is encoded as an object and `additionalProperties: false` is added) and annotations. Every source tool has
property-level descriptions. After an upgrade that changes the recipe, re-run the command (see
[upgrading.md](upgrading.md)). `php artisan gate:audit` lists the enabled tools with their ceilings, field
classes and rule hashes, without values.

## `canaries.values`

Default: `[]`. Canary values. A response or serialized output that contains one of them (case-insensitive, after
text normalisation) is blocked. Set them from your environment in the published file, and keep them out of version
control. Example with a fake value: `'values' => ['FAKE_FIXTURE_ONLY_canary']`. Empty values are ignored.

## `firewall.ceiling`

Default: `'internal'`. The most sensitive class the stdio caller may receive. `gate:check` accepts `public`,
`internal`, `personal` and `secret`; any other value fails. `secret` passes the check but means nothing is served:
no tool returns anything. A `personal` ceiling never shows a raw personal value either; the contract decides the form.
HTTP callers get their ceiling from their token instead (see [tokens.md](tokens.md)).

## `firewall.limits.per_call_bytes`

Default: `null` (code default 16384; cap 65536). Largest response the gate returns for one call. Longer responses are
truncated. `docs.read` uses the same effective value (strict: 16384) clamped to the cap.

## `firewall.limits.per_session_bytes`

Default: `null` (code default 409600; cap 2097152). Bytes returned per caller session before further calls are refused. The counter is keyed by caller id on both
transports.

## `firewall.limits.per_day_bytes`

Default: `null` (code default 5242880; cap 20971520). Bytes returned per caller id per UTC day before further calls
are refused.

## `firewall.limits.calls_per_minute`

Default: `null` (code default 60; cap 300). Calls allowed per minute for one caller. Exceeding it refuses the call. The count is taken at call entry; a call
refused for configuration (`config.invalid`) before that point is not counted.

## `classification`

Default: the source pseudo-tables (`source_routes`, `source_config_keys`, `source_schema_items`, `source_docs`,
`source_openapi_endpoints`), with every column `internal`. This is a map of table, then column, then class
(`public`, `internal`, `personal` or `secret`). A column that is not listed is unclassified and is dropped from
responses. Publishing the file replaces the whole map, so keep the source entries when you add your own tables.

## `http.path`

Default: `'mcp/context-gate'`. The route of the MCP endpoint over HTTP. Requests must carry a bearer token. The route is registered whether or not `enabled` is `true`, and is skipped only when
the route cache is in use.

## `http.origins`

Default: `[]`. Exact `Origin` values (scheme, host and port) that a browser may send, for example
`'https://app.example.test'`. An empty list refuses every browser `Origin`. Requests without an `Origin` header are
non-browser clients and are accepted. See [tokens.md](tokens.md#the-origin-rule).

## `http.refusals_per_minute`

Default: `null` (code default 60; cap 600). Requests refused with 401 or 403 that one (client IP, presented credential)
pair may cause per minute. Past the limit that pair gets `429` with `Retry-After` before any Origin, token or database
work, and one `http.throttled` receipt is written for it in that window. A valid token never accumulates refusals, so
it is never answered `429` because of other clients. A coarse per-IP counter (10 times the limit, at most 6000) never
denies service: above it refusals keep their 401/403, the origin check skips its token lookup, and refusal receipts
are suppressed except one summary per IP per window. A configured value can only lower the cap. Counters live in the
gate cache store (`cache_store`) with a 60 second window; the IP is `$request->ip()`, so behind a proxy the host must
configure trusted proxies (the proxy's address, not `*` from untrusted networks). See
[tokens.md](tokens.md#refusal-throttle).

## `cache_store`

Default: `null`, the host default cache store. Holds budgets, rate limits, and the day, week and persistent-session
pseudonym state. Use a store shared by every worker (redis, database); `array` and `file` stores break the limits
across workers. `gate:check` fails on a value that is not a string. See [extending.md](extending.md).

## `channel`

Default: `'context-gate'`. The log channel that receives one receipt line per call. Receipts go to stderr only and
hold metadata and hashes, never values.

## `sources.routes.exclude`

Default: `[]`. Route patterns to hide from the routes source. The framework debug routes stay excluded in code.

## `sources.eloquent.models`

Default: `[]`. Models the schema source may describe. A model not listed is not described. The source, `gate:classify`
and `gate:scan` use each model's own database connection; soft-delete filtering of related rows uses the model's
`DELETED_AT` column.

## `sources.config.env_map`

Default: `[]`. Maps a config key to the environment name it reads, for the config source. For example
`'services.example.key' => 'EXAMPLE_KEY'`. The source reports names and types, never values.

## `sources.docs.paths`

Default: `[]`. Roots, relative to the application root, that the docs source may read. Nothing outside these roots is
readable. The denylist (env files, keys, certificates) matches after Unicode folding (NFKC and case fold); a path that
is not valid UTF-8 is refused.

## `sources.openapi.paths`

Default: `[]`. Roots that the OpenAPI source may read. Only `.json`, `.yaml` and `.yml` files are read.

## `sources.openapi.allowlist`

Default: `[]`. Hosts an OpenAPI URL source may read from. An empty list refuses every URL.

## `sources.openapi.access`

Default: `'read'`. The only accepted value. Connector write access is not available; any other value fails `gate:check`.

## `sources.openapi.timeout_seconds`

Default: `null`: the active profile's value (strict: 2.5 s; balanced: the code cap 5.0 s), never above the code cap
5.0 s. Fetch timeout for an OpenAPI URL. A value above the cap is reported by `gate:check`.

## `sources.openapi.max_bytes`

Default: `null`: the active profile's value (strict: 1 MB; balanced: the code cap 2097152 bytes), never above the code
cap 2097152 bytes. Largest OpenAPI document read. A value above the cap is reported by `gate:check`.

## `sources.openapi.examples`

Default: `drop`. `drop` removes example values from the OpenAPI document. `redacted` keeps them and passes each value
through redaction, the canary check and quarantine. Any other value is treated as `drop`, and `gate:check` fails.

## Commands that read this file

- `php artisan gate:install`: publishes the file.
- `php artisan gate:check`: validates it; exit `0` only when no check fails.
- `php artisan gate:audit`: reports the enabled tools it configures.
- `php artisan gate:fingerprint <tool>`: prints a tool's fingerprint (name, description and input schema).
