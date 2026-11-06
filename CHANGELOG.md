# Changelog

All notable changes to this project are documented in this file.

## Unreleased

- Fixed: on Laravel 13, a host that requires a morph map (`Relation::requireMorphMap()`) could not authenticate any gate token: resolving the token's holder threw `ClassMorphViolationException`. The holder now resolves without the morph map, and a token owned by any other type still resolves to no holder.
- CI: the PHP 8.5 `highest` leg resolves for the runner's PHP instead of the locked `platform.php` 8.2, so it now runs Laravel 13 and fails when it does not.
- Fixed: on PostgreSQL, a bearer whose id part is not a bigint (`abc|…`, `|…`, a 20-digit id) made the token lookup a query error, answered `500` with no receipt and no refusal throttle. Such a bearer is now refused as `token.invalid` (`401`) without a query.
- Fixed: `gate:check` failed every reachable Redis store with "did not return the probe value", because Redis reads a number back as a string and the probe compared it strictly with the integer `1`. The probe now writes and compares a random string. The test fake reads numbers back the way `RedisStore` does.
- Fixed: the production guards used `APP_ENV === production` while the profile treats every environment outside `local`, `development`, `staging` and `testing` as strict. Under `prod`, `live` or an unknown name, stdio started without a token, `gate:preview` and `gate:simulate` ran, and `gate:check` only warned about a file store or a stdio-only setup. Every guard now follows the profile rule.
- Receipt ids (the `Reference: r_...` a failed call shows) carry 64 random bits instead of 32: `r_` + 16 hex characters. At 32 bits two receipts share an id with even odds after about 77 000 of them, which several workers reach within a day.
- Fixed: on Redis, a refusal counter that expired between `add` and `increment` was recreated without a TTL and never expired, so a credential stayed throttled (`429`) or an IP's anonymous refusal receipts stayed silenced for good. The counter now gets its TTL back with `EXPIRE`, which leaves concurrent increments intact. The file store keeps this documented ceiling.
- Fixed: `routes.list` and `config.keys` masked ordinary kebab-case names as secrets once their words added up to 20 letters (`discipline-qualification-methods-list`), and masked route-binding placeholders with a field (`{post:slug}`). The letters-only token shape is now judged per word; tokens split by hyphens (hex UUIDs, mixed letters and digits) are still masked.
- Fixed: redaction matched email addresses in ASCII only, so `jürgen.müller@gmail.com` became `jürgen.mü[EMAIL]` and an address with a non-ASCII domain was not redacted at all. Addresses in any script are now redacted whole. IBANs are also redacted in lower case and in the printed form with spaces between groups of four (`DE89 3704 0044 …`), which the phone pattern used to cut in half. A hex-like run that looks like an IBAN (two letters, two digits, eleven or more letters and digits) is now redacted in lower case too.
- Fixed: a `relation` field read the related table directly, past every global scope of the related model except soft deletes, so a record the host hides with a scope (another tenant, an erased person) was returned through the relation. Related records are now read through the model's own query, with its global scopes, and still as raw attributes (no casts, no accessors). A scope that needs a request context the gate call does not have hides the record or fails the relation; it never widens it.
- Fixed: rows dropped by the work bound (twice the per-call limit, at most 10 000 rows) were not reported when the transforms then shrank the remaining rows below the per-call limit: the model got a partial result with no truncation notice. The cut now sets the truncation flag. The 10 000-row cap also applies before relations, so one call no longer loads an unbounded list of foreign keys in one query.
- Fixed: invalid settings could take the host down or switch off the gate's own refusals.
  - The provider validated the settings in `register()`, building every tool before the application's providers ran, so a tool that needed an application binding failed the host's boot, `artisan` and `config:cache` included. The provider now only reads the receipt channel there.
  - A tool class that exists but is not a `Tool`, or a tool that cannot be built, is a settings violation (`config.invalid` per call, named by `gate:check`) instead of a `500` on every HTTP request.
  - Over HTTP, auth refusals keep the gate's `401`/`403`, `WWW-Authenticate` and receipt when the settings are invalid; before, they fell through to the host's exception handler. Stdio still refuses to start on an invalid tool entry.
- Removed: the `callers` config key, with no compatibility path (this changes what an existing config must contain). Replace each entry with a role: define `roles` (name, `tools`, `ceiling`, optional `limits`), issue tokens with `gate:token --role=<name>`, and map stdio OS users with `stdio_roles`. A config that still has `callers` fails `gate:check`. Tokens issued before this release have no role and see no tools: reissue them.
- Caller roles: a token carries its role as the ability `gate:role:<name>`, set at issuance, so a new token needs no config change. `gate:token --ceiling` defaults to the role's ceiling and may only narrow it. A call's ceiling is the narrower of the role's and the token's (or `firewall.ceiling` over stdio). Roles may lower the profile limits per caller.
- A caller with no role, or with a role that is not defined, sees no tools; its calls answer as unknown tools with the new reason `role.unknown`. Every receipt records the caller's `role` (`none` when there is none), and the classification rule hash covers the effective role.
- One settings path: every value the package reads comes from one validated settings object, validated once per process. An invalid value refuses calls with `config.invalid` and `gate:check` names its key; a value is never read as a default when it did not validate.
- The package's source tables (`source_routes`, `source_config_keys`, `source_schema_items`, `source_docs`, `source_runbook_rows`, `source_openapi_endpoints`) are defined in code. A published config file ships `classification` empty; a host can hide a built-in table or column, and any other change to one fails `gate:check`.
- Receipt rule hashes cover the effective values of the call: the merged classification, the limits, and the ceiling that applies to the call. Over HTTP that is the token's ceiling; before, the classification hash used the stdio ceiling for every caller.
- `gate:check --json` prints `{"results": [...], "effective": {...}}` instead of a list of results.
- A hidden built-in source column is reported as `field.unlisted` on the receipt, not `field.unclassified`.
- Redis is the recommended and tested `cache_store`. A new reason code, `store.unavailable`, refuses a call when the gate store fails: no handler runs and no row is served; over HTTP the request is answered `503` before the token check. `gate:check` classifies the store by driver (array and null fail, file fails in production, database warns) and probes Redis, reporting its eviction policy and an empty prefix.
- Every gate key moves under `context-gate:`. Budgets, rate limits and refusal counters restart once at the upgrade and expire on their own TTL (a day at most), so a caller can get one extra day budget that day.
- Opt-in concurrency tests run against Redis in CI (`CONTEXT_GATE_STORE=redis`), with exact outcomes and a TTL sweep.
- Quality gates for maintainers: a mutation job over the security scope (minimum score 90, no ignore list), a line-coverage floor for `src/` (92%), finding records that tie each fixed review finding to the test that guards it, and a check that no `src/` comment cites a spec ID. No runtime behaviour changes.

## [0.1.1]

Released 2026-11-01.

- A version tag now releases automatically: the CI gate and the tag guards run first, then the archive is built, attested and published.
- A token without `gate:read` answers `403` with `insufficient_scope`; a token without a ceiling answers `401`.
- Every refusal carries the RFC 6750 `WWW-Authenticate` value for its case (`invalid_token`, `insufficient_scope`, or none without a token).
- Config keys are checked against their types and caps: a configured `firewall.ceiling` is `public` or `internal` only.
- The stdio end-to-end tests no longer depend on a discovery cache, so CI can run them from a clean checkout.

## [0.1.0]

Released 2026-11-01.
### Security

- `Field::date()` accepts only `year` and `month`; the old `day` / `Y-m-d` fallback leaked exact dates.
- `Field::bucket()` requires 1 to 20 strictly ascending breaks; with two or more, every adjacent gap is at least `max(2, 5%)` of the span between the first and last break. Fine buckets re-identified raw values. Nulls in bucket and date columns pass through as null.
- A contract that breaks a load rule is refused per call as `config.invalid` (it used to only warn on stderr), so a contract column without a catalog class refuses the call instead of being dropped.
- The tool fingerprint is the sha256 of the canonical JSON of name, description, input schema (an empty `properties` as an object, `additionalProperties: false` added) and annotations. Re-run `php artisan gate:fingerprint <tool>` after changing a tool's description, schema or annotations; it prints the new fingerprint to paste into `fingerprint`.
- The canary check also runs before redaction (stage `canary.pre`), so a canary inside a text field, including an email-shaped one, raises `canary.hit`.
- Canary matching folds common Latin, Cyrillic and Greek look-alikes (23-entry map).
- The normaliser strips all Cf and default-ignorable characters and Hangul fillers.
- Quarantine phrase matching tolerates whitespace and more verbs.
- The redactor redacts local-format phone numbers.
- One shared masking rule for config keys and route segments: identifier-only keys are shown, token-like segments are masked.
- OpenAPI YAML pre-parse guard: at most 100 aliases, 64 indentation levels and 64 bracket nesting levels.
- `schema.describe` invokes only relation methods declared on the model class itself with an explicit `Relation` return type.
- Token expiry fails closed on unexpected values.
- `docs.read` denylist matching folds Unicode (NFKC and case fold); text that is not valid UTF-8 is refused.
- A valid token that lacks `gate:read`, has an unknown ability or has no or several ceilings gets `403` with `insufficient_scope`; `401` is only for a missing, invalid, expired or revoked token.
- Unexpected exceptions become an `isError` result with a reference and never escape, even with `app.debug`.
- Tool names are validated; every tool declares annotations, read-only and closed-world by default, and `openapi.endpoints` is open-world.
- HTTP refusal throttle: refusals are counted per (client IP, presented credential), and past `http.refusals_per_minute` that pair gets `429` before any origin, token or database work. A coarse per-IP counter (10 times the limit, at most 6000) only bounds log volume and never denies: above it anonymous refusal receipts are suppressed except one `http.throttled` summary per IP per window; refusals of a known token are always recorded. The package does not deny service by IP and does not stop token rotation; flood protection belongs at the reverse proxy or WAF. Behind a proxy configure `trustProxies`.

### Changed

- A configured `firewall.ceiling` accepts only `public` and `internal`. `personal` and `secret` are refused with `config.invalid` on the call path, and `gate:check` reports a FAIL naming `firewall.ceiling`.
- Receipt rule hashes change once for installs that removed the `firewall.ceiling` key; the stock config already sets `internal`, so most installs do not change.
- Budgets and the rate limit are reserve-then-check with atomic increments; a rate-limited call now also counts as a hit.
- Day and week pseudonym state is written under a cache lock.
- Session pseudonyms are cached with a one-hour sliding TTL over HTTP (in memory over stdio, capped at 100 scopes).
- `tools/list` adds annotations (`readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`) and property-level descriptions for every source tool. The server sets an `instructions` text and advertises only the `tools` capability.
- Argument validation checks nested properties, enum, length, pattern, items and min/max (integers strictly).
- An error is noted as one metadata-only line on the receipt channel: receipt reference, exception class, file basename and line, never the message.
- `Pseudonymizer`, `Redactor` and `ToolPolicy` are no longer final and are the defaults of the `IssuesPseudonyms`, `RedactsText` and `AuthorizesTools` interfaces (bind the interface to replace). Tools and contracts also resolve via the container and may take constructor dependencies; OpenAPI uses the bound `Transport` and `Resolver`.
- The provider no longer overwrites a host-defined `logging.channels.<channel>` or `auth.guards.context-gate`, registers Sanctum only if not already loaded, and computes rule hashes lazily. The HTTP guard and the refusal renderables are registered on every boot; only the `Mcp::web` route is skipped when routes are cached, so `route:cache` and `optimize` work.
- `enabled = false` hides every tool; `gate:preview` reports the same.
- A bad argument is an `isError` result (`Invalid arguments: ...`); `-32602` stays for a missing name or an unknown tool.
- A second content block `Notice: ...` tells the model about truncation or flagged untrusted text.
- `docs.read` runs the canary check before redaction. Its per-call limit is the effective profile value (strict 16384) clamped to the cap, and the text is trimmed so the JSON-encoded row fits.
- Pseudonyms are issued in one batch per call (one lock, load and save). A scope holds at most 10 000 entries and its key rotates beyond that; a single batch above 10 000 distinct ids fails closed. Over HTTP the session state slides one hour but never lives past creation plus 7 days. An unreadable cached state (rotated `APP_KEY`, corruption) is replaced. A cache that refuses to store the map fails with `pseudonym.keyfail`.
- Budget counters guard against a lost TTL and never go negative. Rows are capped before pseudonymising (twice the per-call limit, at most 10 000) as a work bound; the real truncation still happens on the final rows.
- Schema, classify and scan commands and the token checks use the model's own database connection; soft-delete filtering uses the model's `DELETED_AT` column. The contract guard cache key covers the contract's fields, table and related contracts. Failure notes cut anonymous class names.
- Over HTTP (no STDERR) boot diagnostics go once per process to the receipt log channel.
- Receipt rule hashes follow the effective (profile) values.
- `gate:check` verifies the PHP extensions curl, mbstring and intl and reports unknown tool entry keys.

- `CeilingCheck` (dead code) is removed. The HTTP ceiling travels through the container instead of mutating config.
- Argument order on a call: hidden or unknown tool, configuration refusal, per-minute admit, canary and contract rules, schema validation of the arguments as sent, pseudonym resolution, policy, ceiling and contract, fetch, firewall, budget.
- Under `--json`, console errors go to stderr and stdout stays empty (`gate:check --json` still prints valid JSON and exits `1` on failure).
- `gate:scan` prints `Models: 0 (set sources.eloquent.models to scan models)` when no models are configured and still scans routes; `gate:check` verifies the catalog entry for `openapi.endpoints`.
- Constitution 1.1.0 (principles VII and IX): see `.specify/memory/amendments/2026-11-01-vii-ix.md`.

### Added

- Config key `cache_store`.
- `gate:token` prints the caller key `token:<id>` and a hint to add it under `callers`; `gate:token --list` has a `caller` column.
- `Tool::annotations()`.
- `gate:install` prints `php artisan migrate` as a next step.
- A table of receipt reason codes in `docs/privacy.md`, and a compatibility policy in the README. Classes are marked `@api` or `@internal`.
- `ext-curl` is a declared requirement.
- Publish tags `context-gate-config` and `context-gate-migrations`.
- `ContractValidator::violation()`.
- Interfaces `ContextGate\Contracts\RedactsText`, `IssuesPseudonyms` and `AuthorizesTools` (`@api`); `Redactor`, `Pseudonymizer` and `ToolPolicy` implement them as the defaults.
- Config key `http.refusals_per_minute` (default 60, cap 600) and the `GateRefusalThrottle` middleware: past the limit, clients that cause 401 or 403 refusals get `429` with `Retry-After: 60`; one `http.throttled` receipt per client per window (29th reason code).
- `gate:scan --output=<path>`, `gate:token --list --json`, `gate:preview --json` (`text`, `truncated`, `dropped`).

### Fixed

- A lost counter TTL no longer leaves a budget counter stuck, and a counter never goes negative.
- `docs.read` no longer returns empty text when the first line exceeds the limit.

### Configuration (spec 007)

- Strict is the default profile. On a default install the firewall budget is unchanged (16384 bytes per call, 409600 per session, 5242880 per day, 60 calls per minute). The OpenAPI fetch and local read change from 5 seconds and 2 MB to 2.5 seconds and 1 MB; set `sources.openapi.timeout_seconds` and `sources.openapi.max_bytes` (up to their caps) to keep the old values.
- `profile` = `balanced` selects the code caps. Production always runs strict.
- A caller that lists a tool absent from `tools` makes every call by every caller refuse with `config.invalid` until the list is fixed. `gate:check` names `callers.<id>.tools`. Accepted for MVP.
- Per-tool `max_bytes` and `calls_per_minute` are not supported (tasks T032 and T033 of spec 007 are dropped).

### Developer commands (spec 004)

- `gate:classify` writes a draft classification catalog with `// suggested` and `// needs decision` markers; it refuses to overwrite without `--force`.
- `gate:scan` proposes a catalog draft from routes and models, with `--json` printing to stdout and `--output=<path>` writing a file; it changes no configuration.
- `gate:preview` prints the response a caller would receive for a tool, and the dropped fields; it sends nothing and writes a `source: preview` receipt.
- `gate:canary` prints one email and one token canary with suggested placements; it changes no file.
- No command prints a row value, a config value or a document value.

### OpenAPI source (spec 004)

- `openapi.endpoints` tool: per endpoint, the method, path, parameters, request and response schemas and security requirement names, through a contract.
- Example fields (`example`, `examples`, `x-example`) are removed by default.
- Documents come from a local file or from an allowlisted URL (`sources.openapi.allowlist`); an empty allowlist refuses every URL.
- URL fetches refuse private, loopback, link-local and metadata addresses, pin the checked address for the request, refuse redirects and external `$ref` targets, time out (code cap 5 seconds; the strict profile default is 2.5 seconds, see spec 007) and refuse documents over the byte cap (code cap 2 MB; strict default 1 MB) or not OpenAPI 3.x.
- Config keys `sources.openapi.paths` and `sources.openapi.allowlist`, both empty by default.

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
