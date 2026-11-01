# Research: One Path to Settings

State checked at HEAD `d744441` (2026-11-01). Since the spec was written, spec 007 phases 8–9 (version store,
overlay, simulator) and spec 018 (runbooks) landed; both add settings readers, counted below.

## R1. Inventory of direct reads

`grep -rn "config('context-gate" src` (excluding `config_path(...)`): **55 reads in 35 files**; 52 of them outside
`src/Settings/`. Each is assigned to the typed accessor that replaces it (R2).

| File:line | Key read | Replacement |
|---|---|---|
| `src/Console/AuditCommand.php:31` | `classification` | `classification()` |
| `src/Console/AuditCommand.php:40` | `tools` | `tools()` |
| `src/Console/CheckCommand.php:44` | `classification` | `classification()` |
| `src/Console/CheckCommand.php:54` | whole document | `document()` (ReadinessContext, R5) |
| `src/Console/CheckCommand.php:86` | `tools` | `tools()` |
| `src/Console/CheckCommand.php:119` | `sources.{docs,openapi}.paths` | `docsPaths()`, `openApiPaths()` |
| `src/Console/CheckCommand.php:136` | `sources.eloquent.models` | `models()` |
| `src/Console/CheckCommand.php:142` | `sources.config.env_map` | `envMap()` |
| `src/Console/CheckCommand.php:148` | `tools` | `tools()` |
| `src/Console/ClassifyCommand.php:38` | `sources.eloquent.models` | `models()` |
| `src/Console/FingerprintCommand.php:21` | `tools.<name>.class` | `tools()` |
| `src/Console/ScanCommand.php:32` | `sources.eloquent.models` | `models()` |
| `src/Console/SimulateCommand.php:33` | `callers` | `callers()` (spec 010 replaces with `roles()`) |
| `src/Console/TokenCommand.php:87, 166` | `channel` | `channel()` |
| `src/ContextGateServiceProvider.php:86` | `tools` (rule hash) | moves to `RuleHashes` (R4) |
| `src/ContextGateServiceProvider.php:121` | `classification` (rule hash) | moves to `RuleHashes` (R4) |
| `src/ContextGateServiceProvider.php:134, 135` | whole document (overlay install) | `ConfigOverlay::install()` inside `src/Settings/` |
| `src/ContextGateServiceProvider.php:140` | `channel` (stderr channel default) | `ConfigOverlay::install()` returns it, or `channel()` |
| `src/Http/GateAuthRefusal.php:51` | `http.path` | `httpPath()` |
| `src/Http/GateOriginCheck.php:30` | `http.origins` | `origins()` |
| `src/Http/HttpRoute.php:51` | `http.path` | `httpPath()` |
| `src/Policy/ToolPolicy.php:18` | `tools.<name>.gate` | `tools()` |
| `src/Runbook/RunbookRegistry.php:24` | `runbooks` | `runbooks()` |
| `src/Server/Caller.php:25` | `callers.<id>.tools` | `callers()` (010: `roles()`) |
| `src/Server/Canaries.php:20` | `canaries.values` | `canaries()` |
| `src/Server/ContractGuard.php:26` | `classification` | `classification()` |
| `src/Server/Failure.php:24` | `channel` | `channel()` |
| `src/Server/FirewallRunner.php:67` | `classification` | `classification()` |
| `src/Server/GateCallTool.php:49` | `tools` | `tools()` |
| `src/Server/GateListTools.php:23` | `enabled` | `enabled()` |
| `src/Server/GateListTools.php:39` | `tools` | `tools()` |
| `src/Server/GateServer.php:64` | `tools` | `tools()` |
| `src/Server/GateServer.php:90` | `channel` | `channel()` |
| `src/Server/GateServer.php:126, 129` | `classification`, `tools` | `classification()`, `tools()` |
| `src/Server/GateServer.php:149` | `enabled`, `canaries.values` | `enabled()`, `canaries()` |
| `src/Server/PolicyStep.php:36` | `enabled` | `enabled()` |
| `src/Server/Receipt.php:79` | `channel` | `channel()` |
| `src/Server/Simulator.php:48` | `tools` | `tools()` |
| `src/Server/Simulator.php:68` | `callers` | `callers()` (010: `roles()`) |
| `src/Server/Sources/DocsReadTool.php:52` | `sources.docs.paths` | `docsPaths()` |
| `src/Server/Sources/OpenApiEndpointsTool.php:68, 74` | `sources.openapi.paths`, `.allowlist` | `openApiPaths()`, `openApiAllowlist()` |
| `src/Server/Sources/SchemaDescribeTool.php:46, 49` | `classification`, `sources.eloquent.models` | `classification()`, `models()` |
| `src/Server/Tool.php:53` | `tools` | `tools()` |
| `src/Server/ToolRegistry.php:78` | `tools` | `tools()` |
| `src/Source/ConfigSource.php:27` | `sources.config.env_map` | `envMap()` |
| `src/Source/RoutesSource.php:32` | `sources.routes.exclude` | `routeExcludes()` |
| `src/Support/GateCache.php:20` | `cache_store` | `cacheStore()` |
| inside the layer: `src/Settings/ConfigAudit.php:23`, `ConfigOverlay.php:68`, `EffectiveSettings.php:60` | | stay (they are the layer) |

Indirect readers that already go through `EffectiveSettings` statics but recompute on each call:
`src/Pipeline/Budget.php:44`, `src/Http/RefusalLedger.php:48`, `src/Server/Sources/DocsReadTool.php:59`,
`src/Server/Sources/OpenApiEndpointsTool.php:75-76`, `src/Server/Caller.php:48`, `src/Console/AuditCommand.php:41`,
`src/Console/CheckCommand.php:100, 132`, import/rollback/export commands. They switch to the typed accessor so the
values are computed once per process (FR-003, FR-007).

Readiness checks read `$ctx->config` (the document passed by `gate:check`): `ContractCheck`, `RunbookCheck`,
`SettingsCheck`, `CapsCheck`, `OriginCheck`, `CanaryCheck`, `TokenCallersCheck`, `UrlAllowlistCheck`,
`TransportCheck` (R5).

## R2. The typed accessor

**Decision**: `ContextGate\Settings\ActiveSettings` (already the validated-once singleton, built in
`ContextGateServiceProvider::register`) becomes the one path. It keeps `valid()` and `violations()` and gains typed
getters. Consumers resolve it with `app(ActiveSettings::class)` (or constructor injection where the class is
container-built). Getters:

| Getter | Return type |
|---|---|
| `enabled()` | `bool` |
| `tools()` | `array<string, ToolEntry>` (readonly value object: `class`, `enabled`, `gate`, `fingerprint`, `contract`) |
| `callers()` | `array<string, list<string>>` (removed by spec 010) |
| `canaries()` | `list<string>` |
| `classification()` | `array<string, array<string, Ceiling>>` effective map (R3) |
| `ceiling()` | `Ceiling` (stdio ceiling, `public` or `internal`) |
| `limit(string $key)` | `int` / `float` effective value (profile, caps, env) |
| `limits()` | `array<string, int|float>` |
| `httpPath()`, `channel()` | non-empty `string` |
| `origins()`, `openApiAllowlist()`, `docsPaths()`, `openApiPaths()`, `models()`, `routeExcludes()` | `list<string>` |
| `envMap()` | `array<string, string>` |
| `examples()` | `'drop'|'redacted'` |
| `cacheStore()` | `?string` |
| `runbooks()` | `array<string, class-string>` |
| `document()` | the validated document (for `gate:check` and export only) |
| `environment()` | `string` |

**Fail closed (FR-005)**: when `valid()` is false, every getter except `channel()`, `valid()`, `violations()`,
`document()` and `environment()` throws `ContextGate\Settings\InvalidSettings` (a `LogicException`). The call path
already refuses with `config.invalid` before any consumer reads (`ResponseBuilder::configRefusal`,
`PolicyStep`); `GateListTools` checks `valid()` first and returns no tools. A getter that throws on a path that forgot
to check is caught by the last-resort handler and is a failure, never a default.

`channel()` is the one exception: on an invalid document it returns the package default `context-gate`, because the
`config.invalid` refusal must itself write a receipt (constitution IV outranks FR-005 for that one key; flagged for
the spec owner).

**File size**: `ActiveSettings` would exceed 250 lines with the getters; the source-list getters move to a small
`SourceSettings` value held by `ActiveSettings::sources()` if needed (decided at implementation by `composer length`).

**`EffectiveSettings`**: keeps its pure function `for(array $document, string $env)` (used by validation and by the
simulator tests). Its static readers `current()`, `limit()`, `ceiling()`, `examples()`, `document()` are removed;
`ActiveSettings` computes the values once in its constructor. The "explicit value above cap takes the profile value"
fallback in `for()` becomes unreachable for consumers (an above-cap value makes the document invalid) and is removed
(FR-007).

**Alternatives rejected**: a new `Settings` class beside `ActiveSettings` (two objects with the same job); a facade
(public API, FR-014); passing arrays (untyped, FR-003).

## R3. Built-in source tables and merge

**Decision**: `src/Settings/BuiltinClassification.php` holds the six built-in tables as a constant: the five named in
the spec plus **`source_runbook_rows`** (`step`, `status`, `text`, all `internal`), added by spec 018 after the spec
was written. `config/context-gate.php` ships `classification => []`, and its "keep these entries" comment is removed.

Host notation for hiding (spec leaves notation to the plan):

```php
'classification' => [
    'source_docs' => false,                       // hide the whole table
    'source_routes' => ['middleware' => false],   // hide one column
    'orders' => ['id' => 'internal', 'email' => 'personal'],
],
```

Merge (`ClassificationMerge::effective(array $host): array`):

1. Start from the built-in map.
2. For each host table with prefix `source_`: unknown table name: violation `classification.<table>` ("the source_
   prefix is reserved"). `false`: drop the table. An array: each column `false` drops it; a column equal to its built-in
   class is no change (FR-010); any other class: violation `classification.<table>.<column>`; a column not built in:
   violation `classification.<table>.<column>`.
3. Host tables without the prefix are added as given (classes validated as `public|internal|personal|secret`).

The merged map is computed once in `ActiveSettings`. A hidden column is absent from the catalog, so the existing
projection drops it with `field.unlisted` (spec US3 scenario 2) and the source tool still runs (edge case).

**Alternatives rejected**: `'hidden'` as a class string (reads like a fifth class); a separate `hide` list (two keys
to keep in sync); deep merge by `mergeConfigFrom` (Laravel merges the top level only, and it would not stop a
reclassification).

## R4. Rule hashes from effective values

**Today**: `ContextGateServiceProvider::ruleHashes()` is a process singleton (`context-gate.rules`). Its
classification hash covers the raw `classification` config plus `EffectiveSettings::ceiling()` (the stdio ceiling)
and the effective limits. Over HTTP the token's ceiling is ignored (spec assumption confirms the bug).

**Decision**: move the hashing into `src/Settings/RuleHashes.php`. `tool` and `contract` hashes stay computed once
per process (lazy). The `classification` hash is `RuleHash::of(canonical([merged classification, effective limits,
call ceiling]))`, memoized per ceiling value (at most three). `ResponseBuilder` asks
`RuleHashes::forCall(Caller::ceiling())` instead of the singleton. Spec 010 adds the role definition to the input.

FR-013 follows from hashing the merged map and effective values: a repeated built-in table and an omitted one give
the same merged map, so the same hash.

## R5. `gate:check` reads the same object

`CheckCommand` builds `ReadinessContext` from `app(ActiveSettings::class)->document()` (the post-overlay document the
runtime validated), not from `config('context-gate')`. Checks are part of validation and keep reading
`$ctx->config`; `SettingsCheck` reports `ActiveSettings::violations()` instead of re-running the validator, so the
check and the runtime cannot disagree (FR-006). The "values in force" report (SC-003) is a new `--json` field
`effective` listing `limits()`, `ceiling()` and the merged classification table names.

## R6. Typed schema rules (FR-004)

New leaf types in `SettingsSchema`: `string+` (non-empty string), `list<string>`, `list<string+>`,
`map<string,string>`. Replacements:

| Key | Old | New |
|---|---|---|
| `callers.*.tools` | any | `list<string+>` (until 010 removes `callers`) |
| `canaries.values` | any | `list<string+>` |
| `classification` | any | custom rule (R3), in `ClassificationMerge::violations()` |
| `http.path` | any | `string+` |
| `http.origins` | any | `list<string+>` (format still checked by `OriginCheck`) |
| `channel` | any | `string+` |
| `sources.routes.exclude`, `sources.eloquent.models`, `sources.docs.paths`, `sources.openapi.paths`, `sources.openapi.allowlist` | any | `list<string+>` |
| `sources.config.env_map` | any | `map<string,string>` |
| `sources.openapi.examples` | any | `enum:drop|redacted` |

A wrong type is a violation naming the key; it is never read as empty (US1 scenario 3).

## R7. Architecture test

`tests/Unit/Settings/SingleSettingsPathTest.php` tokenizes `src/**/*.php` outside `src/Settings/` and fails on a
`config` function call (or `config()->get`, `Config::get`, `Arr::get(config(...` ) whose first string argument starts
with `context-gate`. `config_path('context-gate.php')` is a different function and is not matched. It also fails on
`EffectiveSettings::` outside `src/Settings/` once R2 is done.

## R8. Ordering with specs 008, 010, 014

- 010 replaces `callers()` with `roles()` and `stdioRoles()`; 009 types `callers` only so FR-004 holds in between.
- 008 (Redis) reads `cache_store` via `cacheStore()`; independent.
- 014 resolves scope values per call in the host; nothing here.
