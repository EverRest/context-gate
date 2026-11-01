# Implementation Plan: Contract Query Tool with Pagination

**Branch**: `012-contract-query-tool` | **Date**: 2026-11-01 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/012-contract-query-tool/spec.md`

## Summary

A host declares a query tool as a config tool entry with `class => QueryTool::class`, a `contract` and a `query`
array (filters with operators, sort fields, page size, optional unfiltered opt-in). The package generates the tool's
description and input schema from the contract and the declaration, so the existing fingerprint pins it. Filters are
accepted only in the forms of FR-006 (plain eq/in/range at or below the ceiling, pseudonym eq resolved on the server,
whole buckets, year/month periods); everything else is `input.invalid` or `filter.denied`. The query is built with the
query builder from declared column names and bound values only, scoped by spec 014's `CallScope::apply`, ordered by the
sort plus a hidden key tie-breaker, and read one page plus a probe row. Rows pass every firewall stage unchanged. When
more rows exist, the response carries an opaque random cursor whose position and binding live only in the cache store,
with a TTL and a cap of 100 live cursors per caller. Receipts record filter names and operators, page and cursor flags,
never values.

**Order**: depends on spec 014 (row scopes) for `CallScope::apply` and `CallScope::bindingKey`, and so on spec 010.
Recommended order 010 → 014 → 012 → 013 (research R13). Spec 013 reuses `FilterRules` and `FilterCompiler` from this
plan unchanged.

## Technical Context

**Language/Version**: PHP 8.2+ (`composer.json` `php: ^8.2`)

**Primary Dependencies**: `laravel/framework` ^12 || ^13 (query builder, cache), `laravel/mcp`. No new dependency
(spec Out of Scope; constitution X).

**Storage**: cursor state and the per-caller cursor index in the gate cache store (`GateCache::store()`), each with a
TTL. Queries run on the contract model's own connection and table, read only.

**Testing**: PHPUnit via `composer test`; Orchestra Testbench with in-memory sqlite seeded fixtures; `GateCallHarness`;
array cache store (and the Redis concurrency suite of spec 008 for the cursor index lock); no network.

**Target Platform**: stdio and HTTP.

**Project Type**: Laravel package.

**Performance Goals**: one SQL query per page (`limit page_size + 1`) plus one per pseudonym filter; keyset paging, so
page N costs the same as page 1 on an indexed sort column.

**Constraints**: no SQL text from caller input; no value in cursors, receipts or logs; page size ≤ 200, TTL ≤ 60 min,
`in` ≤ 20, 100 live cursors per caller, all code caps; files under `src/` ≤ 250 lines.

**Scale/Scope**: tables up to millions of rows (keyset paging); up to 100 live walks per caller.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How this plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass | Filters only in forms that cannot reveal a value above the ceiling (R4); no filter on text; no sort on derived personal forms; rows pass every firewall stage (FR-005); a cursor holds no value (R7); an untrusted position stops the walk instead of guessing (R8). |
| II. Read-Only by Default | Pass | Query builder `select` only; no write method is reachable from the tool. |
| III. Deny by Default | Pass | The generated tool is off until enabled, gated, allowed per caller and pinned (FR-003). Unfiltered listing is off unless declared (FR-026). Invalid cursors share one response. |
| IV. No Response Without a Receipt | Pass | Every page, refusal and invalid cursor writes a receipt before the response; the cursor is consumed only after the receipt (R7). |
| V. Code Decides | Pass | Operators, ceilings, bucket and date bounds and the cursor binding are code; the description is not a control. |
| VI. Config Narrows | Pass | `query.page_size` and `query.cursor_ttl_seconds` only lower code caps; the `in` cap and live-cursor cap are code only; a declaration can only lower the page size. No stage is added or reordered by this spec. |
| VII. Failures Quiet to Model | Pass | `cursor.invalid` reuses the invalid-arguments text; `filter.denied` the generic refusal; reasons only in receipts. |
| VIII. Production Authenticated | Pass | No transport change. |
| IX. Test-First | Pass | tasks.md puts each test first; the privacy suite seeds personal values, canaries and filter sentinels and checks responses, cursors and receipts (SC-003). |
| X. Minimal Dependencies | Pass | Cache store and query builder only. |

**Result**: all gates pass. No complexity entries beyond the generated-tool class (below).

## Project Structure

### Documentation (this feature)

```text
specs/012-contract-query-tool/
├── plan.md
├── research.md                      # R1–R13
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── query-tool-input.schema.json
│   └── cursor-and-response.md
├── checklists/requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
src/Query/                           # new namespace ContextGate\Query
├── QueryTool.php                    # generated Tool: name = entry key, description/schema from declaration (R1, R3)
├── QueryDeclaration.php             # parse + validate the `query` array; canonical form (R2)
├── FilterRules.php                  # operators per form/class, schema fragments, ceiling rule (R4) — reused by spec 013
├── FilterCompiler.php               # parsed filters -> where clauses with bound values (R5) — reused by spec 013
├── Filter.php                       # one parsed filter (column, op, bounds)
├── QueryArguments.php               # call arguments -> filters, sort, page size; input.invalid / filter.denied (R4)
├── QueryRunner.php                  # builds and runs the page query, keyset predicate (R5, R6)
├── QueryPlan.php, QueryPage.php     # per-call state bound in the container (data-model)
└── Cursors.php                      # issue, read, validate, consume, retire; TTL and index (R7)
src/Server/QueryStep.php             # QueryTool-only step after ScopeStep (R12)
src/Server/FirewallStep.php          # after the firewall: next cursor, receipt, consume, response (R8)
src/Server/ResponseBuilder.php       # cursor in structuredContent and Notice (R9); query receipt field
src/Server/Receipt.php               # optional `query` field (R10)
src/Server/ReasonCode.php            # CursorInvalid, FilterDenied (R11)
src/Server/ToolRegistry.php          # instantiate(): new QueryTool($key)
src/Server/PseudonymArguments.php    # record() exposed as resolveOne() for pseudonym filters
src/Server/GateCallTool.php          # runs QueryStep for a QueryTool
src/ContextGateServiceProvider.php   # `query` declaration in the tool rule hash (R3)
src/Readiness/Checks/QueryCheck.php  # FR-004 violations; lists unfiltered opt-ins (R2)
src/Readiness/CheckRegistry.php      # registers QueryCheck
src/Pipeline/Caps.php                # QUERY_PAGE_SIZE 200, CURSOR_TTL 3600, FILTER_IN_VALUES 20, CURSORS_PER_CALLER 100
src/Settings/{SettingsSchema,Profiles}.php, src/Config/CapsValidator.php   # query.page_size, query.cursor_ttl_seconds
config/context-gate.php              # query.page_size, query.cursor_ttl_seconds => null

tests/
├── Fixtures/QueryEntryRecord.php, QueryEntryContract.php, QueryTables.php (seed 250 rows, personal values, canaries)
├── Unit/Query/                      # declaration, rules, compiler, arguments, runner (keyset), cursors, tool schema
├── Unit/Readiness/Checks/QueryCheckTest.php
├── Unit/Server/ReasonCodeTest.php, ReceiptQueryTest.php
├── Contract/Query/                  # call-level stories, combination matrix, walks, cursor cases, budgets, privacy
└── Concurrency/CursorIndexProcessTest.php   # cap of 100 under parallel issue (spec 008 harness)
```

**Structure Decision**: Everything query-specific lives in `src/Query/`. The filter rules and compiler are separate
classes with no paging or cursor knowledge so spec 013 can use them as they are. The call path gains one step
(`QueryStep`) that runs only for `QueryTool`; hand-written tools are untouched. The generated tool follows the
`RunbookTool` precedent for construction from a config key.

## Design Decisions

- **Declaration** (R1, R2): config entry `query` array on a `QueryTool` entry; validated at load, per call
  (`config.invalid`) and in `gate:check`.
- **Schema and pin** (R3): generated; `Fingerprint::of` unchanged; `query` canonical added to the tool rule hash.
- **Filters** (R4): table in research R4; `filter.denied` for a plain field above the ceiling; pseudonym filters go
  through the existing pseudonym resolution.
- **Query** (R5, R6): builder only, bound values, scope first, keyset with nulls-last and the key tie-breaker, probe row.
- **Cursor** (R7): random id, server-side state with binding hash, TTL, consume after serve, 100 per caller.
- **Response and receipts** (R9, R10): cursor in structured content and notice; `query` receipt field.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| A generated `Tool` class built from config (second after `RunbookTool`) | FR-001: no host class per question | A host-written class per question is the status quo the spec replaces |
| Server-side cursor state with an index per caller | FR-016 forbids encoding a position in the cursor; FR-020/021 need consume and retire | A signed or encrypted cursor would carry the position to the client (research R7) |
