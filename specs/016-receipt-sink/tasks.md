# Tasks: Durable Receipt Sinks and Operator Metrics

**Input**: Design documents from `specs/016-receipt-sink/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R9), data-model.md, contracts/. Specs 001–007 complete. Spec 015
is not required (its hash change flows into `rules.classification` unchanged). Spec 009 is not required (research R2).
The Complexity Tracking items need owner acknowledgement before T013 and T019 (**[DECIDE]**).

**Tests**: REQUIRED (constitution IX). Each implementation task follows a test task that must fail first. Tasks marked
*verification* lock existing behaviour. No test uses the network.

**Organization**: by user story. US1 and US2 are P1; US3 and US4 are P2; US5 is P3.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1…US5 from spec.md
- Paths are relative to the repository root

---

## Phase 1: Setup

- [ ] T001 [P] Write test `tests/Unit/Pipeline/ReceiptCapsTest.php`: `Caps::RECEIPT_RETENTION_DAYS` 180, `Caps::SINK_TIMEOUT` 1.0, `Caps::AUDIT_WINDOW_DAYS` 31, `Caps::AUDIT_LIMIT` 1000
- [ ] T002 Edit `src/Pipeline/Caps.php` to add the four constants
- [ ] T003 [P] Write test `tests/Unit/Settings/ReceiptsSettingsTest.php`: `receipts.sinks` null is valid; a sink map with no `required` sink is a violation on `receipts.sinks`; unknown `kind`, `mode` or `format` are violations naming the key; two database sinks are a violation; `format` on a database sink is a violation; `retention_days` 181 and `timeout_seconds` 1.5 are violations naming the key; equal to cap passes; `config/context-gate.php` defaults are `sinks` null, `retention_days` 30, `timeout_seconds` 1.0, `connection` null, `table` `gate_receipts`
- [ ] T004 Edit `config/context-gate.php` (receipts block with comments), `src/Settings/SettingsSchema.php`, `src/Settings/SettingsValidator.php` and `src/Config/CapsValidator.php` (research R2)
- [ ] T005 [P] Write test `tests/Unit/Server/ReceiptIdTest.php`: ids match `^r_[0-9a-f]{24}$`; 10000 ids are distinct (FR-017)
- [ ] T006 Edit `src/Server/Receipt.php` id to `bin2hex(random_bytes(12))`; update the `{8}` id patterns in `tests/Unit/Server/ReceiptTest.php`, `tests/Contract/CanaryBlockTest.php`, `tests/Contract/PseudonymKeyFailCallTest.php`, `tests/Contract/ReviewFixesRuntimeTest.php`, `tests/Contract/ReviewRound2Test.php`, `tests/Contract/HandlerFailureTest.php`, `tests/Contract/ProjectionTest.php`, `tests/Contract/TransformFailureTest.php`, `tests/Contract/CanaryPlainFieldTest.php` to `{24}`

---

## Phase 2: Foundational

**Purpose**: the sink abstraction and the required/best-effort rule.

**Checkpoint**: `SinkSet` with fake sinks is green; `Receipt::write` still writes the same line through it.

- [ ] T007 [P] Create `tests/Fakes/FailingSink.php` (throws a named exception class with a message holding `FAKE_FIXTURE_ONLY`) and `tests/Fakes/SlowSink.php` (sleeps a given time, records writes). Fixtures only
- [ ] T008 [P] Write test `tests/Unit/Receipt/LogSinkTest.php` *characterization*: `LogSink` in `line` format writes exactly the line `Receipt::write` writes today (same keys, same order, `JSON_UNESCAPED_SLASHES`) on the configured channel; a logger that throws makes `write()` throw
- [ ] T009 Create `src/Receipt/Sink.php` and `src/Receipt/LogSink.php` (line format only)
- [ ] T010 [P] Write test `tests/Unit/Server/ReasonCodeSinkTest.php`: `ReasonCode::SinkFailed` is `sink.failed` with decision `note`
- [ ] T011 Edit `src/Server/ReasonCode.php`
- [ ] T012 [P] Write test `tests/Unit/Receipt/SinkSetTest.php`: all required ok → every sink got the record; a required `FailingSink` → `ReceiptNotWritten` with a fixed message (no `FAKE_FIXTURE_ONLY`) and the other required sink gets a `sink.failed` note; a best-effort `FailingSink` → no throw, each required sink gets a note record with `caller` `system`, `decision` `note`, `note.receipt` = the receipt id, `note.sink` = its name, `note.exception` = the class only; a best-effort `SlowSink` above the timeout → note with `SinkTimeout`; best-effort sinks run after required ones; `fromSettings()` with null sinks gives one required line log sink (FR-003); with invalid sink settings gives that default (research R2)
- [ ] T013 **[DECIDE]** timeout ceiling. Create `src/Receipt/SinkSet.php` and `src/Receipt/ReceiptNotWritten.php`; edit `src/Server/Receipt.php` to deliver through `app(SinkSet::class)`; bind the singleton in `src/ContextGateServiceProvider.php`

---

## Phase 3: User Story 1 - Several sinks, one clear rule for "written" (Priority: P1)

**Goal**: a response is returned only when every required sink wrote the receipt.

**Independent Test**: required log + best-effort failing sink → call served with a note; required failing sink →
generic failure and no data.

- [ ] T014 [P] [US1] Write test `tests/Contract/Receipts/RequiredSinkFailureTest.php` (`tests/Contract/GateCallHarness.php`, fake sinks bound in the container): a required sink that fails on the allow receipt makes `tools/call` return only `Request failed.` (no rows, no reference) (SC-001); the same on a refusal path (hidden tool) and on the last-resort `crashed()` path; nothing escapes `handle()`; the framework exception handler is not called (spy)
- [ ] T015 [P] [US1] Write test `tests/Contract/Receipts/BestEffortSinkFailureTest.php`: a best-effort failing sink with a required log sink returns the response and the log holds the receipt and one note naming the sink and the receipt id (SC-002)
- [ ] T016 [P] [US1] Write test `tests/Contract/Receipts/HttpReceiptFailureTest.php`: with the required sink failing, an HTTP refusal answers 503 with a generic JSON-RPC error and no 401 body
- [ ] T017 [US1] Edit `src/Server/GateCallTool.php` (catch `ReceiptNotWritten` before `Throwable`), `src/Server/ResponseBuilder.php` (`unrecorded()`), `src/Http/GateRefusalThrottle.php` (503)
- [ ] T018 [P] [US1] Write test `tests/Contract/Receipts/NoRequiredSinkTest.php`: a configuration whose sinks are all best-effort makes `gate:check` exit 1 naming `context-gate.receipts.sinks` and refuses every call with `config.invalid`, and that receipt is still written to the fallback log sink (FR-002, US1 #4; logic from T004 and T013)

**Checkpoint**: principle IV holds with any sink mix.

---

## Phase 4: User Story 2 - Store receipts in a database table with retention (Priority: P1)

**Goal**: rows with expiry, hidden after expiry, pruned on command.

**Independent Test**: short retention, clock past it: no query result, prune deletes.

- [ ] T019 [P] [US2] Write test `tests/Contract/MigrationRegistrationTest.php` (extend): the `gate_receipts` migration is loaded and publishable; columns and indexes match contracts/gate-receipts-table.md
- [ ] T020 [US2] Create `database/migrations/2026_11_01_000000_create_gate_receipts_table.php`
- [ ] T021 [P] [US2] Write test `tests/Unit/Receipt/DatabaseSinkTest.php`: one row per record with copied columns and `record` JSON equal to the log record; `expires_at` = `at` + retention days (frozen clock); retention capped at 180 even if a larger value is passed; a duplicate id throws (sink failure)
- [ ] T022 [US2] **[DECIDE]** timeout ceiling. Create `src/Receipt/DatabaseSink.php`; in `src/ContextGateServiceProvider.php` define `database.connections.context-gate-receipts` from `receipts.connection` or `database.default` when the host has not, with `PDO::ATTR_TIMEOUT` = the timeout
- [ ] T023 [P] [US2] Write test `tests/Contract/Receipts/HostTransactionTest.php` (temporary SQLite file shared by both connection names): a receipt written inside `DB::transaction()` that rolls back is still in `gate_receipts` (FR-019)
- [ ] T024 [P] [US2] Write test `tests/Unit/Receipt/ReceiptQueryTest.php`: rows past `expires_at` are never returned whatever the filters (SC-004); filters by caller, tool, reason, decision and window; newest first; limit applied; window > 31 days and limit > 1000 throw naming the cap
- [ ] T025 [US2] Create `src/Receipt/ReceiptQuery.php`
- [ ] T026 [P] [US2] Write test `tests/Contract/Receipts/PruneCommandTest.php`: `gate:receipts:prune` deletes every expired row (SC-004), keeps live rows, prints only `Pruned <n> receipts.`; without a database sink exits 1
- [ ] T027 [US2] Create `src/Console/ReceiptPruneCommand.php` and register it in `src/ContextGateServiceProvider.php`
- [ ] T028 [P] [US2] Write test `tests/Unit/Readiness/Checks/ReceiptSinkCheckTest.php`: database sink and no table → FAIL naming `gate_receipts`; expired rows → WARN with the count only; required log sink on a `null`-driver channel → WARN; default config → PASS (FR-018)
- [ ] T029 [US2] Create `src/Readiness/Checks/ReceiptSinkCheck.php` and register it in `src/Readiness/CheckRegistry.php`

---

## Phase 5: User Story 3 - Structured log format for Loki (Priority: P2)

**Goal**: versioned, fixed-key JSON lines with bounded label fields.

**Independent Test**: one receipt per reason code; each line validates against contracts/receipt-structured-v1.schema.json.

- [ ] T030 [P] [US3] Write test `tests/Unit/Server/ReceiptTransportTest.php`: receipts carry `transport` = `http` when `context-gate.transport` is bound to http, `stdio` under the stdio server, `cli` otherwise
- [ ] T031 [US3] Edit `src/Server/Receipt.php` (add `transport`) and `src/Server/GateServer.php` (bind `context-gate.transport` in `boot()`)
- [ ] T032 [P] [US3] Write test `tests/Unit/Receipt/StructuredLineTest.php`: for one receipt per `ReasonCode` case and one note, the line parses, `v` is 1, the key set equals the schema's `required` list exactly, values match the schema types (a small validator in the test, no new dependency) (SC-006, FR-010)
- [ ] T033 [P] [US3] Write test `tests/Unit/Receipt/LabelBoundTest.php`: across all reason codes, tools of the fixture registry and the three transports, each label field (`decision`, `reason`, `transport`, `tool`, `source`) takes values from a finite list known before the run, and neither `caller` nor `id` is a label in the documented list (FR-011)
- [ ] T034 [US3] Create `src/Receipt/StructuredLine.php`; edit `src/Receipt/LogSink.php` to use it for `format: structured`
- [ ] T035 [P] [US3] Write test `tests/EndToEnd/StructuredStdoutPurityTest.php`: with `format: structured` over stdio, stdout holds only protocol messages (pattern of `tests/EndToEnd/StdoutPurityTest.php`) (US3 #3)

---

## Phase 6: User Story 4 - Query stored receipts with `gate:audit` (Priority: P2)

**Goal**: `gate:audit --receipts` per contracts/receipt-commands.md.

**Independent Test**: seed two callers; query one caller and a window; only its receipts print.

- [ ] T036 [P] [US4] Write test `tests/Contract/Receipts/AuditQueryTest.php`: caller filter and window print only matching rows, newest first (US4 #1); no match exits 0 with `No receipts.` (US4 #2); `--json` stdout is only a JSON array (US4 #3); no database sink exits 1 with the message (US4 #4); window above cap exits 1 naming 31 days (US4 #6); limit above cap names 1000
- [ ] T037 [P] [US4] Write test `tests/Contract/Receipts/AuditQueryAuditLineTest.php`: each run writes one `receipts.query` line with `actor` = `app('context-gate.caller')` and the filter keys, and none of the filter values (US4 #5, FR-014)
- [ ] T038 [US4] Edit `src/Console/AuditCommand.php` to add `--receipts` with its options; move the query printing to `src/Console/AuditReceipts.php` if the file would exceed 250 lines
- [ ] T039 [P] [US4] Write test `tests/Contract/AuditJsonTest.php` *verification* (extend): `gate:audit` and `gate:audit --json` without the new flags print the spec 006 tool listing unchanged

---

## Phase 7: User Story 5 - Operator metrics (Priority: P3)

**Goal**: metric definitions in code and docs; `gate:audit --summary`.

**Independent Test**: one seeded receipt per metric counts 1 each.

- [ ] T040 [P] [US5] Write test `tests/Unit/Receipt/MetricsTest.php`: one seeded receipt per metric of research R8, plus one preview and one simulated receipt for each; every metric counts exactly 1 (SC-005)
- [ ] T041 [US5] Create `src/Receipt/Metrics.php`
- [ ] T042 [P] [US5] Write test `tests/Contract/Receipts/AuditSummaryTest.php`: `gate:audit --summary` prints every metric with its count, zeros included; `--json` prints only the object; no caller id appears in the output (US5 #2)
- [ ] T043 [US5] Edit `src/Console/AuditCommand.php` (or `AuditReceipts.php`) to add `--summary`

---

## Phase 8: Privacy across sinks

- [ ] T044 [P] Write test `tests/Contract/Receipts/SinkPrivacyTest.php`: with log (line), log (structured) and database sinks all enabled, calls that carry seeded sentinels (`tests/Fixtures/SeededPii.php`), canary values (`tests/Fixtures/CanaryValues.php`), a bearer token and a client IP in arguments, results and request leave none of them in any log line, structured line, table column or `record` JSON, including `sink.failed` notes and the query audit line (SC-003, FR-007)

---

## Phase 9: Polish and Cross-Cutting

- [ ] T045 [P] Create `docs/receipts.md`: sinks and modes, what "written" means and that a log sink cannot confirm durability (FR-004), table, retention, pruning schedule, structured format v1 and label fields, Loki pipeline example, `gate:audit --receipts` and `--summary`, metric table (FR-016), timeout ceiling
- [ ] T046 [P] Write test `tests/Contract/DocsReceiptsSyncTest.php`: every metric name in `Metrics` and every key of the structured schema appears in `docs/receipts.md` (pattern of `tests/Contract/DocsConfigSyncTest.php`)
- [ ] T047 [P] Update `docs/configuration.md` (`receipts` keys and caps), `README.md` (receipts section, 96-bit ids), `CHANGELOG.md` (Unreleased: sinks, table, structured format, `transport`, `sink.failed`, id length, new commands)
- [ ] T048 If spec 009 is merged: edit `SinkSet::fromSettings()` to read the 009 settings path, test first in `tests/Unit/Receipt/SinkSetTest.php` (research R2)
- [ ] T049 Confirm every new or edited `src/` file is within 250 lines and every test file within 400 (`scripts/check-file-length.php`)
- [ ] T050 Run `composer style`, `composer stan`, `composer length`, `composer test`; read each exit code; all must be 0
- [ ] T051 Run quickstart.md steps 1–5 on a fixture host app and record the result

---

## Dependencies and Execution Order

- Phase 1 → Phase 2 → stories. T013 is the switch every story uses.
- US1 (Phase 3) before US2: the database sink is exercised through `SinkSet`.
- US2 before US4 and US5 (they read the table). US3 needs only Phase 2.
- Phase 8 needs US1–US3. [P] tasks touch different files. Each test task fails before its implementation task
  (except *characterization* and *verification*).
