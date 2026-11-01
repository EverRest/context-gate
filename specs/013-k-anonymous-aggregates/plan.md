# Implementation Plan: k-Anonymous Aggregates

**Branch**: `013-k-anonymous-aggregates` | **Date**: 2026-11-01 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/013-k-anonymous-aggregates/spec.md`

## Summary

Adds a declared aggregate tool: row counts grouped by at most two allowed keys over a contract's table, with every
cell below k suppressed, an "other" group and a total shown only when they cannot reveal a suppressed count, a
per-caller daily aggregate-query budget, and the same floor applied to the existing `Field::aggregate()` form. Filters
come from the spec 012 filter unit; the row scope comes from spec 014 and is applied inside the query. No new
dependency, no table.

**Order**: implement after spec 012 (filter unit, declared-tool factory, receipt filter shape) and spec 014 (scope
resolution, `scope.unresolved`). Phase 2 (US-independent foundations) and the `Field::aggregate()` change (US2 part)
can start before them; tasks that need 012 or 014 say so.

## Technical Context

**Language/Version**: PHP 8.2+ (`composer.json` `php: ^8.2`)

**Primary Dependencies**: `laravel/framework` ^12 || ^13, `laravel/mcp`. No new dependency (research R4, R7).

**Storage**: the contract model's own database connection, read-only, one grouped `SELECT … COUNT(*) … GROUP BY` per
call; the daily counter in `GateCache::store()` with a TTL to the next 00:00 UTC.

**Testing**: PHPUnit via `composer test`; Orchestra Testbench; SQLite in memory for the grouped query; array cache
store; no network.

**Target Platform**: stdio (developer) and HTTP (spec 005).

**Project Type**: Laravel package.

**Performance Goals**: one query per call; folding bounded by `Caps::AGG_SCAN_CELLS` (10 000).

**Constraints**: k floor 5 in code; config only raises k and lowers the other limits (constitution VI). No label,
count or filter value in receipts (IV). Receipt before response (IV). Determinism: byte-identical responses on
unchanged data (FR-013).

**Scale/Scope**: about 8 new classes in `src/Aggregate/`, edits to 8 existing files.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How this plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass | Group-by only on public/internal plain values and non-raw forms (R1); personal date only as year/month and only with counts ≥ k; cells 1..k-1 never named; total withheld when it would reveal them (R3). `Field::aggregate()` small counts become null (R8). Secret never groupable. Unclassified column = `config.invalid`. |
| II. Read-Only by Default | Pass | One `SELECT` per call; no write path. |
| III. Deny by Default | Pass | The generated tool is an ordinary tool entry: off until enabled, gate ability, caller list, fingerprint pin (R10). |
| IV. No Response Without a Receipt | Pass | `AggregateStep` writes the receipt before returning; every refusal path uses `ResponseBuilder::receipt`. Receipt holds names, operators, k and counts of groups only (R9). |
| V. Code Decides | Pass | Suppression is a pure function (R3); limits and k are code constants. |
| VI. Config Narrows | Pass | k: config and declaration may only raise, floor 5 / cap 1000 fail `gate:check` otherwise (R2). Other limits only lower. k kept out of `Profiles` so no profile can lower it. |
| VII. Failures Quiet | Pass | Existing generic texts; `Notice:` strings fixed and free of labels and counts; reason codes in receipts only. |
| VIII. Production Authenticated | Pass | No transport change. |
| IX. Test-First | Pass | Every implementation task in tasks.md follows a failing test; the SC-001 brute-force matrix and the SC-003 byte-identity test come first. |
| X. Minimal Dependencies | Pass | No noise library, no package; query builder and cache only. |

**Result**: all gates pass. The residual differencing risk (two overlapping queries) is accepted by the owner and
documented (FR-016), not a constitution violation.

## Project Structure

### Documentation (this feature)

```text
specs/013-k-anonymous-aggregates/
├── plan.md
├── research.md              # R1–R10
├── data-model.md
├── quickstart.md
├── contracts/aggregate-tool.md
├── spec.md
└── tasks.md
```

### Source Code (repository root)

```text
src/Aggregate/                       # new namespace ContextGate\Aggregate
├── AggregateDeclaration.php         # parse + violations() of an entry's `aggregate` key (R1, R2)
├── AggregateSettings.php            # effective k, max_group_by, max_groups, queries_per_day (R2)
├── Suppression.php                  # pure k rule, other/total rule, cut, percentages (R3)
├── AggregateResult.php              # value object: groups, other, total, withheld, cut, suppressed
├── AggregateQuery.php               # grouped SQL with scope + 012 filters, fold to labelled cells (R4–R6)
├── AggregateBudget.php              # per-caller UTC-day counter with TTL (R7)
├── AggregateTool.php                # generated Tool: name, description, schema from declaration (R10)
└── AggregateStep.php                # query → suppression → normalise → canary → budget → receipt → response

src/Pipeline/Caps.php                # AGG_* constants
src/Pipeline/Transformer.php         # Field::aggregate() floor: 1..k-1 → null + dropped entry (R8)
src/Server/FirewallRunner.php        # passes k to Transformer
src/Server/ReasonCode.php            # AggregateBudget, AggregateSuppressed
src/Server/Receipt.php               # optional `aggregate` field (R9)
src/Server/ResponseBuilder.php       # receipt(..., aggregate:), aggregate result + notices
src/Server/Admission.php             # AggregateBudget::admit for aggregate tools
src/Server/GateCallTool.php          # dispatch AggregateTool to AggregateStep
src/Server/ToolRegistry.php          # build AggregateTool from an `aggregate` entry (via 012 factory)
src/Settings/SettingsSchema.php      # `aggregates.*` keys, `tools.*.aggregate`
src/Settings/SettingsValidator.php   # floor rule for aggregates.k; declaration violations
src/Readiness/Checks/ContractCheck.php   # report declaration violations by tool and column
config/context-gate.php              # `aggregates` block with null defaults

tests/
├── Unit/Aggregate/                  # declaration, settings, suppression matrix, budget, query fold
├── Unit/Pipeline/TransformerTest.php    # aggregate floor
├── Contract/Aggregate/              # end-to-end calls through GateCallHarness: US1–US5, receipts, determinism
└── Contract/PrivacySuiteTest.php    # seeded personal values and canaries absent from aggregate responses
```

**Structure Decision**: one namespace, `src/Aggregate/`. `Suppression` is pure (arrays in, value object out) so the
SC-001 matrix needs no database. The generated tool is dispatched to its own step because its output is labels and
counts, not contract fields; it reuses `Normalizer`, `CanaryCheck`, `Budget::handle` and `ResponseBuilder`.

## Design Decisions

### Call path for an aggregate tool

`PolicyStep::visibleTool` → `Admission::check` (config valid, per-minute, then `AggregateBudget::admit`) →
`ArgumentsStep` (canary on arguments, schema, pseudonym filter values, `AuthorizesTools`, ceiling) → 014 scope
resolution → `AggregateStep`:

1. validate `group_by` and filters against the declaration and the ceiling (`filter.denied` / `input.invalid`);
2. `AggregateQuery::cells()` — one grouped query, folded to labels, work bound;
3. `Suppression::apply(cells, k, maxGroups)`;
4. build output rows; `Normalizer` → `CanaryCheck` (canary hit blocks, `canary.hit`) → `Budget::handle` (bytes);
5. receipt with `aggregate` metadata, then the response.

### Group-by and k

Per research R1 and R2. Violations are collected by `AggregateDeclaration::violations()` and surfaced by
`SettingsValidator` (so the tool call refuses with `config.invalid`, R8 of spec 007) and by `ContractCheck` in
`gate:check` with the tool and column named.

### Suppression

Per research R3. `Notice:` strings are constants in `Suppression`.

### `Field::aggregate()`

Per research R8. `FirewallRunner::transform` builds the `Transformer` with `AggregateSettings::k()`.

### Documentation

`docs/privacy.md`: section "Aggregates and differencing" (FR-016), reason codes `aggregate.budget`,
`aggregate.suppressed`. `docs/configuration.md`: `aggregates.*` keys and the declaration. `docs/extending.md`:
declaring an aggregate tool. README feature list, CHANGELOG Unreleased (breaking: `Field::aggregate()` small counts).

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Separate `AggregateStep` instead of `FetchStep` + `FirewallStep` | Output columns are labels and counts, not contract fields; `ClassifyCheck` would drop them as unlisted | A pseudo contract per declaration would need generated classification entries for labels, which is more code and would blur the rule that only catalog columns pass |
| Folding labels in PHP (R4) | Period extraction is not portable across SQLite, MySQL, PostgreSQL | Driver-specific SQL needs three code paths and tests per driver; the work bound keeps PHP folding safe |
| Dependency on provisional 012/014 class names | 013 must reuse the 012 filter unit and the 014 scope, not copy them | Copying filter rules creates a second place where a filter could reveal a value |
