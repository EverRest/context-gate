# Changelog

All notable changes to this project are documented in this file.

## Unreleased

### Security

- `Field::date()` accepts only `year` and `month`; the old `day` / `Y-m-d` fallback leaked exact dates.
- `Field::bucket()` requires 1 to 20 strictly ascending breaks, each adjacent gap at least 1% of the larger; fine buckets re-identified raw values.
- A contract that breaks a load rule is refused per call as `config.invalid` (it used to only warn on stderr), so a contract column without a catalog class refuses the call instead of being dropped.
- The tool fingerprint covers name, description and input schema. Every deployment must re-run `php artisan gate:fingerprint <tool>` and update the config.
- The canary check also runs before redaction (stage `canary.pre`), so a canary inside a text field raises `canary.hit`.
- Canary matching folds common Latin, Cyrillic and Greek look-alikes (23-entry map).
- The normaliser strips all Cf and default-ignorable characters and Hangul fillers.
- Quarantine phrase matching tolerates whitespace and more verbs.
- The redactor redacts local-format phone numbers.
- One shared masking rule for config keys and route segments: identifier-only keys are shown, token-like segments are masked.
- OpenAPI YAML pre-parse guard: at most 100 aliases, 64 indentation levels and 64 bracket nesting levels.
- `schema.describe` invokes only relation methods declared on the model class itself with an explicit `Relation` return type.
- Token expiry fails closed on unexpected values.

### Changed

- Budgets and the rate limit are reserve-then-check with atomic increments; a rate-limited call now also counts as a hit.
- Day and week pseudonym state is written under a cache lock.
- Session pseudonyms are cached with a one-hour sliding TTL over HTTP (in memory over stdio, capped at 100 scopes).
- `tools/list` adds `readOnly` annotations.
- Argument validation checks nested properties, enum, length, pattern, items and min/max (integers strictly).
- An error is noted as one metadata-only line on the receipt channel: receipt reference, exception class, file basename and line, never the message.
- `Pseudonymizer`, `Redactor` and `ToolPolicy` are no longer final and are resolved from the container (rebind to replace). Tools and contracts also resolve via the container and may take constructor dependencies; OpenAPI uses the bound `Transport` and `Resolver`.
- The provider no longer overwrites a host-defined `logging.channels.<channel>` or `auth.guards.context-gate`, registers Sanctum only if not already loaded, skips the HTTP route when routes are cached, and computes rule hashes lazily.
- `CeilingCheck` (dead code) is removed. The HTTP ceiling travels through the container instead of mutating config.

### Added

- Config key `cache_store`.
- Publish tags `context-gate-config` and `context-gate-migrations`.
- `ContractValidator::violation()`.

### Fixed

- `docs.read` no longer returns empty text when the first line exceeds the limit.

### Configuration (spec 007)

- Strict is the default profile. On a default install the firewall budget is unchanged (16384 bytes per call, 409600 per session, 5242880 per day, 60 calls per minute). The OpenAPI fetch and local read change from 5 seconds and 2 MB to 2.5 seconds and 1 MB; set `sources.openapi.timeout_seconds` and `sources.openapi.max_bytes` (up to their caps) to keep the old values.
- `profile` = `balanced` selects the code caps. Production always runs strict.
- A caller that lists a tool absent from `tools` makes every call by every caller refuse with `config.invalid` until the list is fixed. `gate:check` names `callers.<id>.tools`. Accepted for MVP.

### Developer commands (spec 004)

- `gate:classify` writes a draft classification catalog with `// suggested` and `// needs decision` markers; it refuses to overwrite without `--force`.
- `gate:scan` proposes a catalog draft from routes and models, with `--json` for `storage/context-gate/scan.json`; it changes no configuration.
- `gate:preview` prints the response a caller would receive for a tool, and the dropped fields; it sends nothing and writes a `source: preview` receipt.
- `gate:canary` prints one email and one token canary with suggested placements; it changes no file.
- No command prints a row value, a config value or a document value.

### OpenAPI source (spec 004)

- `openapi.endpoints` tool: per endpoint, the method, path, parameters, request and response schemas and security requirement names, through a contract.
- Example fields (`example`, `examples`, `x-example`) are removed by default.
- Documents come from a local file or from an allowlisted URL (`sources.openapi.allowlist`); an empty allowlist refuses every URL.
- URL fetches refuse private, loopback, link-local and metadata addresses, pin the checked address for the request, refuse redirects and external `$ref` targets, time out (code cap 5 seconds; the strict profile default is 2.5 seconds, see spec 007) and refuse documents over the byte cap (code cap 2 MB; strict default 1 MB) or not OpenAPI 3.x.
- Config keys `sources.openapi.paths` and `sources.openapi.allowlist`, both empty by default.

## [0.1.0]

### MCP stdio foundation (spec 001)

- `context-gate` MCP server over stdio (`php artisan mcp:start context-gate`), built on `laravel/mcp`.
- Closed-by-default configuration: no tool is visible until enabled, and the tool list is empty by default.
- Hidden and unknown tools share one "not found" response; the reason goes to the receipt only.
- Arguments are validated against the tool schema before the policy and the handler run.
- Tool descriptions are fingerprinted; a changed description hides the tool until it is re-approved.
- Handler failures return `Request failed. Reference: r_...`; the exception text never reaches the caller.
- One metadata-only receipt line per call on the `context-gate` log channel, written to stderr.
- Production refusal: the stdio server does not start when `APP_ENV` is `production`.
- `gate:fingerprint` command prints a tool's description fingerprint.

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
- Volume limits (`firewall.limits`) can lower each default; the code caps are hard maximums that a configured value cannot exceed.
- Receipts carry rule hashes (tool, contract, classification) and dropped-field reasons; no field value, pseudonym key or canary value is written.

### Laravel sources (spec 003)

- Four read-only source tools: `routes.list`, `schema.describe`, `config.keys` and `docs.read`, each returning flattened rows through the firewall pipeline.
- Config keys `sources.routes.exclude`, `sources.eloquent.models`, `sources.config.env_map` and `sources.docs.paths`, all empty by default.
- Pseudo-tables `source_routes`, `source_config_keys`, `source_schema_items` and `source_docs` in the default classification catalog, every column `internal`.
- `docs.read` refuses denylisted paths, symlinks that escape the root and non-text extensions with `source.refused`; the receipt names the refusal step and the response carries no path.
- Sources write nothing to the file system, the database or the configuration; contract tests check modification times and write queries.

### HTTP access and tokens (spec 005)

- `POST` route at `http.path` (default `mcp/context-gate`), registered with `Mcp::web()` and serving the same tools as stdio.
- Bearer gate tokens issued by `gate:token` with `--holder`, `--issuer`, `--ceiling`, `--expires` (at most 90 days) and `--ability`; the token value is shown once.
- `gate:token --list` shows metadata only; `gate:token --revoke` deletes the token and its issuer row and writes `token.revoked`.
- Refusals: 401 with `WWW-Authenticate` for a missing, expired, revoked or unknown token, a token without `gate:read`, or a token with no ceiling; the body names no check.
- Origin allowlist (`http.origins`, exact origins), checked before the token: a foreign origin gets 403 with an empty body. Requests without an `Origin` header pass.
- A token that expires during an open session is refused on its next request.
- `GateTokenHolder` maps to its own class name, so a host morph map does not need an alias for it.
