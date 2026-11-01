# Implementation Plan: Durable Receipt Sinks and Operator Metrics

**Branch**: `016-receipt-sink` | **Date**: 2026-11-01 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/016-receipt-sink/spec.md`

## Summary

`Receipt::write` keeps its signature and hands the record to a `SinkSet`: required sinks first (any failure throws
`ReceiptNotWritten`, so no response is returned), then best-effort sinks (a failure or timeout becomes a `sink.failed`
note on every required sink). Two sink kinds: a log sink (current line or a versioned structured line for Loki) and a
database sink (`gate_receipts`, own connection, expiry per row). `gate:audit` gains `--receipts` and `--summary`;
`gate:receipts:prune` deletes expired rows. Receipt ids grow to 96 bits; receipts gain `transport`. Metrics are
defined once in code and documented from it.

## Technical Context

**Language/Version**: PHP 8.2+.

**Primary Dependencies**: `laravel/framework` ^12 || ^13 (log, database, schema, console). No new dependency.

**Storage**: host database through the framework (`gate_receipts`, separate connection name); log channel.

**Testing**: PHPUnit via `composer test`; Testbench with SQLite (a temporary SQLite file for the separate-connection
test); fake sinks that fail or sleep; seeded sentinel, canary and IP values for SC-003.

**Target Platform**: stdio, HTTP (PHP-FPM), artisan.

**Project Type**: Laravel package.

**Performance Goals**: one insert per receipt on the database sink; no extra work with the default configuration.

**Constraints**: principle IV per required sink; no value, IP or exception message in any sink (I, VII); retention
only lowered by config (VI); no network sink (X).

**Scale/Scope**: up to ~10⁶ receipts per day at the 180-day cap (indexes in contracts/gate-receipts-table.md).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How this plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass | Every sink gets the same metadata-only record; a seeded-sentinel test covers log, structured and table (SC-003). |
| II. Read-Only by Default | Pass | No tool writes; the sink writes the package's own audit table. Queries are artisan-only, not MCP (Out of Scope). |
| III. Deny by Default | Pass | Unchanged; the assistant cannot read receipts. |
| IV. No Response Without a Receipt | Pass | A response is returned only after every REQUIRED sink wrote the receipt; `ReceiptNotWritten` stops it, including on HTTP (503) and on the last-resort path in `GateCallTool`. At least one sink must be required (config validation + fallback default sink when settings are invalid). |
| V. Code Decides | Pass | Required/best-effort rule, caps and metrics are code. |
| VI. Config Narrows | Pass | Retention, timeout, window and limit have code caps; config only lowers. A config without a required sink is invalid. |
| VII. Failures Quiet to Model | Pass | Model gets `Request failed.`; notes carry class names only; `gate:check` names keys and counts. |
| VIII. Production Authenticated | Pass | Unchanged. |
| IX. Test-First | Pass | Each implementation task follows a failing test. |
| X. Minimal Dependencies | Pass | Framework logging and database only; no push to Loki. |

**Result**: pass, with one limitation recorded in Complexity Tracking (timeout bound).

## Project Structure

### Documentation (this feature)

```text
specs/016-receipt-sink/
├── plan.md
├── research.md                 # R1–R9
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── receipt-structured-v1.schema.json
│   ├── gate-receipts-table.md
│   └── receipt-commands.md
└── tasks.md
```

### Source Code (repository root)

```text
src/Receipt/Sink.php                 # interface: name(), write(array $record)
src/Receipt/LogSink.php              # line | structured
src/Receipt/StructuredLine.php       # v1 key list and encoder
src/Receipt/DatabaseSink.php         # insert on context-gate-receipts, expires_at
src/Receipt/SinkSet.php              # required/best-effort rule, notes, timeout, fromSettings()
src/Receipt/ReceiptNotWritten.php    # fixed message, previous kept
src/Receipt/ReceiptQuery.php         # filters, caps, expires_at > now
src/Receipt/Metrics.php              # metric definitions and summary
src/Server/Receipt.php               # 96-bit id, transport, deliver via SinkSet
src/Server/ReasonCode.php            # SinkFailed = 'sink.failed', decision 'note'
src/Server/GateCallTool.php          # catch ReceiptNotWritten first -> 'Request failed.'
src/Server/ResponseBuilder.php       # unrecorded() response
src/Server/GateServer.php            # bind context-gate.transport (stdio|http)
src/Http/GateRefusalThrottle.php     # ReceiptNotWritten -> 503 generic
src/Pipeline/Caps.php                # RECEIPT_RETENTION_DAYS, SINK_TIMEOUT, AUDIT_WINDOW_DAYS, AUDIT_LIMIT
src/Settings/SettingsSchema.php, src/Settings/SettingsValidator.php   # receipts.* keys and rules
src/Config/CapsValidator.php         # receipts caps
src/Console/AuditCommand.php         # --receipts, --summary (query code in src/Console/AuditReceipts.php if over length)
src/Console/ReceiptPruneCommand.php  # gate:receipts:prune
src/Readiness/Checks/ReceiptSinkCheck.php   # table missing, expired rows, discarding channel
src/ContextGateServiceProvider.php   # SinkSet singleton, receipts connection, command registration
config/context-gate.php              # receipts block
database/migrations/2026_11_01_000000_create_gate_receipts_table.php

tests/Fakes/FailingSink.php, tests/Fakes/SlowSink.php
tests/Unit/Receipt/                  # SinkSet, LogSink, StructuredLine, DatabaseSink, ReceiptQuery, Metrics
tests/Unit/Server/ReceiptIdTest.php
tests/Contract/Receipts/             # principle IV, notes, privacy, transaction, commands, prune, check
docs/receipts.md (new), docs/configuration.md, README.md, CHANGELOG.md
```

**Structure Decision**: a new `src/Receipt/` namespace holds the sink mechanics; `Server\Receipt` stays the single
entry point so no writer changes. Query and metrics live next to the sinks they read.

## Design Decisions

- **US1**: `SinkSet` (research R1); fallback default log sink when sink settings are invalid; `ReceiptNotWritten`
  handled in `GateCallTool::handle` before the generic `Throwable` arm, and in the HTTP throttle middleware.
- **US2**: `DatabaseSink` + migration + separate connection + prune command (R3, R4).
- **US3**: `StructuredLine` v1 with fixed keys; `transport` added to every receipt (R6).
- **US4**: `ReceiptQuery` with caps; `gate:audit --receipts` (R7).
- **US5**: `Metrics` definitions; `gate:audit --summary`; docs table generated by hand from the class and pinned by a
  docs-sync test (pattern of `tests/Contract/DocsConfigSyncTest.php`).
- **Spec 009 order**: `SinkSet::fromSettings()` is the only reader of `receipts.*`.
- **Spec 017**: adds `kind`; that is structured `v` 2 and a nullable column in its own migration.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Best-effort timeout is a bound on connect and lock wait, not a hard interrupt (spec edge case says "never delays beyond that timeout") | PHP writes synchronously; a running insert or log handler cannot be interrupted | Deferring writes to process end does not exist under stdio (one process, many calls); a queue adds a host dependency and can lose receipts. Needs owner acknowledgement or a spec wording change |
| `sink.failed` note stored as a record with decision `note` | Notes must reach both the log and the table and be countable (FR-006, FR-016) | A separate note table or line shape would need its own query and metric path |
| New receipt field `transport` (spec 001 format) | FR-011 names transport as a label field; no receipt carries it today | Deriving transport from the caller id is unreliable (`unknown` is used by both HTTP and stdio) |
