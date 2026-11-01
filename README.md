# context-gate

Secure MCP gateway for Laravel: the AI receives only what a contract allows.

The package serves an MCP server over stdio (`context-gate`). Every tool is off until you enable it, and
every call writes one metadata-only receipt line to stderr.

Requirements: PHP 8.2 or later, Laravel 12 or 13. The package is tested on Laravel 12 and Laravel 13.

## Quick start

```bash
composer require everrest/context-gate
php artisan gate:install
php artisan gate:check
php artisan mcp:start context-gate
```

A fresh installation exposes no tools. `gate:check` must exit `0` before the gate serves traffic, and the server
answers an empty tool list until you enable a tool.

## Documentation

- [Installation](docs/installation.md): requirements, install, the first check and a first MCP session.
- [Configuration](docs/configuration.md): every key in `config/context-gate.php`.
- [Tokens](docs/tokens.md): issuing, listing and revoking HTTP access tokens.
- [Privacy](docs/privacy.md): data classes, the response pipeline, canaries and receipts.
- [Security policy](SECURITY.md): how to report a vulnerability.

## Install

```bash
composer require everrest/context-gate
php artisan gate:install
```

`gate:install` publishes `config/context-gate.php` with `enabled` set to `false` and `tools` empty. It does
not overwrite an existing file.

## Enable a tool

1. Set `enabled` to `true` in `config/context-gate.php`.
2. Add the tool under `tools`, with its class, its gate ability and the fingerprint of its description:

   ```php
   'tools' => [
       'echo' => [
           'enabled' => true,
           'class' => App\Mcp\EchoTool::class,
           'gate' => 'echo.allow',
           'fingerprint' => '<output of gate:fingerprint echo>',
       ],
   ],
   ```

3. Allow the tool for a caller. A caller sees and calls only the tools listed for it:

   ```php
   'callers' => [
       'alice' => ['tools' => ['echo']],
   ],
   ```

4. Print the fingerprint with `php artisan gate:fingerprint echo` and paste it into the entry.

A tool the caller is not allowed to see answers with the same "not found" error as a missing tool.

## Classify data

The catalog says which class each column belongs to. It is the `classification` key in `config/context-gate.php`,
a map of table, then column, then class:

```php
'classification' => [
    'orders' => [
        'status' => 'internal',
        'customer_id' => 'personal',
        'notes' => 'personal',
    ],
],
```

The classes, from least to most sensitive, are `public`, `internal`, `personal` and `secret`. Any other value
is rejected when the catalog is loaded. A column that is not in the catalog has no class, and a field with no class is dropped from every
response.

## Write a contract

A contract lists the fields of one model that a caller may receive, in output order. Classes do not go in the
contract: they come from the catalog, so a contract cannot widen what the catalog allows.

```php
namespace App\Gate;

use App\Models\Order;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;

final class OrderContract extends ContextContract
{
    public function model(): string
    {
        return Order::class;
    }

    public function fields(): array
    {
        return [
            'status' => Field::plain(),
            'customer_id' => Field::pseudonym('USR'),
            'total' => Field::bucket([100, 1000]),
        ];
    }
}
```

The forms are:

- `Field::plain()`: the raw value. Allowed for `public` and `internal` columns.
- `Field::pseudonym($prefix, $scope)`: a stable token such as `USR_7f3a`. The scope is `session` (default), `day`
  or `week`. Allowed for `personal` and `internal` columns.
- `Field::bucket($breaks)`, `Field::date($precision)` and `Field::aggregate()`: a band, a date or a count, never the
  raw value.
- `Field::text()`: free text from a source. It is marked as untrusted in the response.
- `Field::relation($contract)`: one related model, one level deep.

A contract is checked against the catalog when it is loaded. It fails the load when:

- a listed column has no class in the catalog;
- a listed column is `secret`;
- a `personal` column uses a form other than pseudonym, aggregate or bucket, so `customer_id` must be a pseudonym.

## Declare a tool's contract

Add the contract to the tool entry, next to its class:

```php
'tools' => [
    'orders.status' => [
        'enabled' => true,
        'class' => App\Mcp\OrderStatusTool::class,
        'gate' => 'orders.status.allow',
        'fingerprint' => '<output of gate:fingerprint orders.status>',
        'contract' => App\Gate\OrderContract::class,
    ],
],
```

A tool without a contract returns nothing: its response is refused.

## Canary values

A canary is a value that must never leave the gate, such as a fake address planted in a fixture. A response, or
the serialized output, that contains a canary is blocked, and the caller gets `Request failed. Reference: r_...`.
A call whose arguments contain a canary is refused before the handler runs. The receipt records `canary.hit`, and
the value itself never appears in a log or receipt.

Set the values from the environment:

```php
'canaries' => ['values' => [
    env('CONTEXT_GATE_CANARY_EMAIL'),
    env('CONTEXT_GATE_CANARY_TOKEN'),
]],
```

```bash
CONTEXT_GATE_CANARY_EMAIL=canary-7f3a@example.invalid
```

Keep real canary values out of the repository: set them in the environment of the machine that runs the server,
not in committed files. A value set to `env()` that is unset is ignored, and the stock `config/context-gate.php` ships
an empty list, so add the `env()` lines above yourself.

## Caller ceiling

The ceiling is the most sensitive class the caller may receive. A field above it is dropped with
`ceiling.exceeded`. Set it with `firewall.ceiling` in `config/context-gate.php`, to `public`, `internal`,
`personal` or `secret`. When the key is absent, the ceiling is `internal`.

A `secret` ceiling returns nothing from any tool.

## Limits and what they count

The volume limits below are the defaults. Set `firewall.limits` in `config/context-gate.php` to lower one; a value
above its code cap is ignored, and `gate:check` reports it. Configuration can lower a limit, never raise it:

| Limit | Default | Code cap |
|---|---|---|
| bytes per call | 16384 | 65536 |
| bytes per session | 409600 | 2097152 |
| bytes per UTC day | 5242880 | 20971520 |
| calls per minute | 60 | 300 |

Per call, a response that is too large is truncated at a row boundary and says so with `truncated: true`. Once the
session or the UTC day has used its limit, the next call is refused.

The session budget and the rate counters are keyed by caller id, not by connection, so two clients of the same OS
user share them. A stdio process whose cache store is `array` or `null` resets its counters on each start.

`calls_per_minute` counts calls to visible tools, per caller, at the moment the call arrives. A call refused for
any other reason still counts. Hidden and unknown tools are not counted, because they are refused before the
counter runs.

## Laravel sources

Four read-only sources answer structure questions without row data. Each is a tool: add its entry under `tools`
with the class and contract below, enable it, and allow it for a caller, as in "Enable a tool".

- `routes.list`: one row per route with method, uri, name, controller, rule names and middleware. Excluded routes are absent.
- `schema.describe`: one row per column, index, cast or relation of a model listed in `sources.eloquent.models`. Secret columns are dropped and counted in one `hidden_columns` row.
- `config.keys`: one row per config key with its type and env name. Values are never returned.
- `docs.read`: one file under `sources.docs.paths`, returned as one untrusted text field.

| Tool | Class | Contract |
|---|---|---|
| `routes.list` | `ContextGate\Server\Sources\RoutesListTool` | `ContextGate\Source\Pseudo\RoutesContract` |
| `schema.describe` | `ContextGate\Server\Sources\SchemaDescribeTool` | `ContextGate\Source\Pseudo\SchemaItemsContract` |
| `config.keys` | `ContextGate\Server\Sources\ConfigKeysTool` | `ContextGate\Source\Pseudo\ConfigKeysContract` |
| `docs.read` | `ContextGate\Server\Sources\DocsReadTool` | `ContextGate\Source\Pseudo\DocsContract` |

The source keys live under `sources` in `config/context-gate.php`. Each list is empty by default, so a source
shows nothing until you name it:

```php
'sources' => [
    'routes' => ['exclude' => ['admin/*']],
    'eloquent' => ['models' => [App\Models\Order::class]],
    'config' => ['env_map' => ['database.connections.mysql.host' => 'DB_HOST']],
    'docs' => ['paths' => ['docs/']],
],
```

- `sources.routes.exclude`: route URI patterns to hide. The framework debug routes (telescope, horizon and similar)
  stay excluded in code.
- `sources.eloquent.models`: the models `schema.describe` may describe. Any other model is refused with `policy.denied`.
- `sources.config.env_map`: config key to the env name it reads. `config.keys` shows `env:DB_HOST`; a sensitive name
  shows `env:<hidden>`; an unmapped key shows no env name.
- `sources.docs.paths`: directories or files, relative to the application root, that `docs.read` may read. Empty
  by default, so every path is refused until you list one. Denylisted paths (the env file, keys, certificates) stay
  refused even when listed.

The source tables are in the default catalog: `source_routes`, `source_config_keys`, `source_schema_items` and
`source_docs`, every column `internal`. Publishing the config replaces the whole `classification` map, so keep
these four entries when you add your own tables. A column left out of the catalog is dropped.

## Storage

The per-minute and budget counters, and the day and week pseudonym keys, use the default cache store
(`cache.default`). That store must keep values between calls in one process: a stdio server is one long-running
process, and a store that drops writes would reset the limits and pseudonyms on every call. Choose the store
in your application config; this package does not pick one.

The session pseudonym keys are held in the server's memory, not in the cache.

## Start the server

```bash
php artisan mcp:start context-gate
```

The server speaks MCP over stdin and stdout. Stdout carries only protocol messages; receipts and
diagnostics go to stderr.

The server refuses to start when `APP_ENV` is `production`. Use authenticated HTTP there instead.

## Serve over HTTP

The same MCP server answers `POST` on a route in the host application, authenticated with a gate token:

```bash
curl -s -X POST http://localhost/mcp/context-gate \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"example","version":"1"}}}'
```

The path comes from `http.path` in `config/context-gate.php` (default `mcp/context-gate`). The `clientInfo` a client
sends does not change its ceiling: the ceiling is the token's.

A request is refused in this order:

1. **Origin**: a browser `Origin` that is not in `http.origins` gets `403` with an empty body. The check runs before
   the token is read, so a foreign origin is refused whether or not a token is sent.
2. **Token**: a missing, expired, revoked or unknown token, a token without `gate:read`, or a token with no ceiling
   or more than one, gets `401` with `WWW-Authenticate: Bearer`. The body never says which check failed; the receipt
   records the reason code.

A request without an `Origin` header is a non-browser client and passes the origin check.

```php
'http' => [
    'path' => 'mcp/context-gate',
    'origins' => ['https://internal-tools.example.com'],
],
```

`http.origins` is a list of exact origins: scheme, host and port, with no path and no wildcard. The literal `null`
origin, an empty `Origin` value and two `Origin` headers are refused even when they are listed.

The host application's morph map must not block `GateTokenHolder`. That model maps to its own class name, so it
needs no alias in the map.

## Issue and revoke tokens

```bash
php artisan gate:token --holder="Example Holder" --issuer="Example Issuer" --ceiling=internal --expires=30d
```

- `--holder`: the name of the person or service the token is for. It is stored as the token name.
- `--issuer`: the name of the person who issues it. Both are required.
- `--ceiling`: `public`, `internal` or `personal`. It is required and sets the most sensitive class the token may
  receive.
- `--expires`: the lifetime in whole days, such as `30d`. It is required and at most `90d`. A missing or longer
  value exits `1` and issues nothing.
- `--ability`: optional and repeatable. Only `gate:read` and the ceiling ability are granted; any other value exits `1`.

The token value is printed once on issuance and is not stored in readable form. Keep it out of shell history and
commit messages.

```bash
php artisan gate:token --list
php artisan gate:token --revoke=<token_id>
```

`--list` shows the id, holder, issuer, ceiling, `expires_at` and `issued_at` of each active token, never the token
value. `--revoke` deletes the token and its issuer row and writes a `token.revoked` receipt; an unknown id exits `1`
and changes nothing. A revoked token is refused on its next request.

## Test

```bash
composer test
composer stan
composer style
composer length
```
