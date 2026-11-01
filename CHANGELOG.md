# Changelog

All notable changes to this project are documented in this file.

## [0.1.0]

## Unreleased

### HTTP access and tokens (spec 005)

- `POST` route at `http.path` (default `mcp/context-gate`), registered with `Mcp::web()` and serving the same tools as stdio.
- Bearer gate tokens issued by `gate:token` with `--holder`, `--issuer`, `--ceiling`, `--expires` (at most 90 days) and `--ability`; the token value is shown once.
- `gate:token --list` shows metadata only; `gate:token --revoke` deletes the token and its issuer row and writes `token.revoked`.
- Refusals: 401 with `WWW-Authenticate` for a missing, expired, revoked or unknown token, a token without `gate:read`, or a token with no ceiling; the body names no check.
- Origin allowlist (`http.origins`, exact origins), checked before the token: a foreign origin gets 403 with an empty body. Requests without an `Origin` header pass.
- A token that expires during an open session is refused on its next request.
- `GateTokenHolder` maps to its own class name, so a host morph map does not need an alias for it.

### Laravel sources (spec 003)

- Four read-only source tools: `routes.list`, `schema.describe`, `config.keys` and `docs.read`, each returning flattened rows through the firewall pipeline.
- Config keys `sources.routes.exclude`, `sources.eloquent.models`, `sources.config.env_map` and `sources.docs.paths`, all empty by default.
- Pseudo-tables `source_routes`, `source_config_keys`, `source_schema_items` and `source_docs` in the default classification catalog, every column `internal`.
- `docs.read` refuses denylisted paths, symlinks that escape the root and non-text extensions with `source.refused`; the receipt names the refusal step and the response carries no path.
- Sources write nothing to the file system, the database or the configuration; contract tests check modification times and write queries.

### Context firewall (spec 002)

- Classification catalog (`classification`: table, column, class), checked when a contract is loaded.
- `ContextContract` with forms: plain, pseudonym, bucket, date, aggregate, text and one-level relation.
- Contract refusals on load: a column with no class, a `secret` column, and a `personal` column without a pseudonym, aggregate or bucket form.
- Per-tool contract declared as `tools.<name>.contract`; a tool without a contract returns nothing.
- Caller ceiling (`firewall.ceiling`, default `internal`); fields above it are dropped with `ceiling.exceeded`.
- Keyed pseudonyms with session, day or week scope; an unknown or expired pseudonym argument is refused.
- Normalisation before the canary check, so zero-width and compatibility-form variants of a canary are still found.
- Canary values from config (`canaries.values`): a hit in arguments refuses the call, a hit in output returns a failed reference.
- Text fields are wrapped as untrusted and passed through redaction.
- Per-call truncation at a row boundary, per-session and per-day byte budgets, and `calls_per_minute` counted at call entry for visible tools.
- Volume limits are fixed in code for this release; config overrides (`firewall.limits`) and `gate:check` are planned.
- Receipts carry rule hashes (tool, contract, classification) and dropped-field reasons; no field value, pseudonym key or canary value is written.

### 0.1.0-dev: MCP stdio foundation

- `context-gate` MCP server over stdio (`php artisan mcp:start context-gate`), built on `laravel/mcp`.
- Closed-by-default configuration: no tool is visible until enabled, and the tool list is empty by default.
- Hidden and unknown tools share one "not found" response; the reason goes to the receipt only.
- Arguments are validated against the tool schema before the policy and the handler run.
- Tool descriptions are fingerprinted; a changed description hides the tool until it is re-approved.
- Handler failures return `Request failed. Reference: r_...`; the exception text never reaches the caller.
- One metadata-only receipt line per call on the `context-gate` log channel, written to stderr.
- Production refusal: the stdio server does not start when `APP_ENV` is `production`.
- `gate:fingerprint` command prints a tool's description fingerprint.
