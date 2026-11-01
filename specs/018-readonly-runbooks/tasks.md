# Tasks: Read-Only Runbooks

**Input**: Design documents from `specs/018-readonly-runbooks/`

**Prerequisites**: plan.md, spec.md (Accepted for planning, 2026-11-01), research.md (R1 to R8), data-model.md,
contracts/, quickstart.md. Specs 001, 002, 003, 006 and 007 must be complete. Cross-spec tasks X001 and X002 must be
applied before Phase 1 code tasks that touch the `runbooks` key or the runbook source.

**Tests**: REQUIRED (constitution IX). Every implementation task is preceded by a test task that must fail first. No
test may touch the network. Spec-edit tasks (X001, X002) are documentation and have no test.

**Organization**: Grouped by user story. US1 and US2 are P1 (console and MCP report). US3 is P1 (read-only by
construction). US4 and US5 are P2 (fingerprint and `gate:check`).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1 to US5 from spec.md
- Paths are relative to the repository root

---

## Phase 1: Setup

- [x] T001 [P] Write test `tests/Unit/Pipeline/RunbookCapsTest.php`: `Caps::RUNBOOK_STEPS` is `20`, `Caps::RUNBOOK_STEP_TIMEOUT` is `5.0`, `Caps::RUNBOOK_STEP_BYTES` is `2048`, `Caps::RUNBOOK_REPORT_BYTES` is `8192`, `Caps::RUNBOOK_RUN_SECONDS` is `30.0` (research R4)
- [x] T002 Edit `src/Pipeline/Caps.php` to add the five `RUNBOOK_*` public constants with a one-line comment each
- [x] T003 [P] Write test `tests/Unit/Settings/RunbooksKeyTest.php`: `SettingsValidator` accepts a document with `runbooks` = `[]` and with `runbooks` = `['disk' => 'App\\Runbooks\\DiskRunbook']`; it reports `runbooks` with a message when the value is not a map of names to class strings; it reports no limit for `runbooks` (FR-002, constitution VI)
- [x] T004 Edit `src/Settings/SettingsSchema.php` to allow the top-level key `runbooks` (map of runbook name to class string); edit `config/context-gate.php` to add `'runbooks' => []` with a comment that configuration maps names to classes only (requires X001)
- [x] T005 [P] Write test `tests/Unit/Settings/RunbookToolKeysTest.php`: a tool entry `runbook.disk` accepts only `enabled`, `class`, `gate`, `fingerprint`, `contract` (FR-019); a `max_bytes` key under it is reported as `config.invalid`
- [x] T006 Cross-spec X001: edit `specs/007-configuration/spec.md` (Amendments section) to add `runbooks` to the valid top-level configuration keys; spec edit only, no test
- [x] T007 Cross-spec X002: edit `specs/002-context-firewall/spec.md` (Amendments section) and `specs/002-context-firewall/data-model.md` to add the runbook source `runbook.<name>` with classification `internal` and ceiling `internal`; spec edit only, no test

---

## Phase 2: Foundational

**Purpose**: The model (steps, statuses, context, registry, runner, report, fingerprint, validator). Every story calls
these; no story adds its own copy.

**Checkpoint**: All Phase 2 unit tests green. The console and MCP stories can then start.

- [x] T008 [P] Write test `tests/Unit/Runbook/StepStatusTest.php`: the enum has exactly `ok`, `warn`, `fail`, `timeout`, `error`; `exitCode()` is 0 for `ok` and `warn` and 1 for the other three (FR-014)
- [x] T009 Create `src/Runbook/StepStatus.php` (backed string enum with `exitCode(): int`)
- [x] T010 [P] Write test `tests/Unit/Runbook/RunbookContextSurfaceTest.php` (SC-001): by reflection, the public methods of `ContextGate\Runbook\RunbookContext` are exactly `envPresent`, `configPresent`, `cacheReachable`, `queueReachable`, `migrationsPending`, `failedJobCount`, `routeExists`; each returns `bool` or `int`; the parameter types match FR-004
- [x] T011 Create `src/Runbook/RunbookContext.php` with the seven read methods over Env, Config, Cache, Queue, DB (migrator and failed-jobs table) and Route; each catches its own exception and returns `false` or `0`; no write call (R5, FR-005)
- [x] T012 [P] Write test `tests/Unit/Runbook/RunbookStepTest.php`: a step name that does not match the pattern throws; `readOnly` is stored as given; a timeout of `0` or above `5.0`, and an output size of `0` or above `2048`, throw; defaults are 2.5 s and 512 bytes
- [x] T013 Create `src/Runbook/RunbookStep.php` (final class, named arguments, `Closure` run) and `src/Runbook/StepResult.php` (status and text)
- [x] T014 [P] Write test `tests/Unit/Runbook/RunbookRegistryTest.php`: names are validated (pattern); a case-only duplicate is reported; a class that does not exist is reported with its key; `all()` keeps declared order and maps each name to its class; a runbook name that is not registered returns null
- [x] T015 Create `src/Contracts/Runbook.php` (interface: `name()`, `description()`, `steps(): list<RunbookStep>`) and `src/Runbook/RunbookRegistry.php` (reads config `runbooks`)
- [x] T016 [P] Write test `tests/Unit/Runbook/RunbookRunnerTest.php` with fixtures in `tests/Fixtures/Runbook/`: steps run in declared order; a throwing step (including a call to a missing method, `Error`) is `error` with the generic text and the other steps still run; a step returning after its timeout is `timeout` and its text is discarded; a text longer than `maxBytes` is cut and marked; once the 30 s run budget is used, the remaining steps are `timeout` with text `Not run: run limit reached.` (R2); the clock is injectable
- [x] T017 Create `src/Runbook/RunbookRunner.php` (runs steps, measures time with a monotonic clock, maps throwables to `error`, returns `StepResult` rows)
- [x] T018 [P] Write test `tests/Unit/Runbook/ReportTest.php`: rows that fit in 8192 bytes are kept in order; a row that does not fit is dropped whole and counted in `droppedRows`; no row is cut in the middle of a word; `bytes` never exceeds 8192
- [x] T019 Create `src/Runbook/Report.php` (builds rows and the 8 KiB cap from runner output)
- [x] T020 [P] Write test `tests/Unit/Runbook/RunbookFingerprintTest.php`: two computations on the same runbook are equal (SC-006); a change to name, description, a step name, `readOnly`, timeout, output size, a step added, a step removed or a step reordered each changes the value
- [x] T021 Create `src/Runbook/RunbookFingerprint.php` reusing the canonical JSON helper in `src/Server/Fingerprint.php` (R6)
- [x] T022 [P] Write test `tests/Unit/Runbook/RunbookValidatorTest.php`: reports, each with the runbook key and the rule, for: missing class; class not implementing `Runbook`; zero steps; 21 steps; duplicate step name; `readOnly` false; a timeout above 5.0 or an output size above 2048; a valid runbook gives no violation
- [x] T023 Create `src/Runbook/RunbookValidator.php` (rules of FR-021 except the fingerprint, which the tool and check add)

---

## Phase 3: User Story 1 - Run a runbook from the console (Priority: P1)

**Goal**: `gate:runbook {name}` prints one row per step and exits 0, 1 or 2 (FR-013, FR-014, FR-015).

**Independent Test**: A three-step runbook (ok, warn, fail) prints three rows in order and exits 1.

### Tests for User Story 1

- [x] T024 [P] [US1] Write test `tests/Contract/Runbook/ConsoleReportTest.php`: a runbook with ok, warn and fail steps prints three rows in declared order with name, status and text (contracts/console-output.md); the exit code is 1
- [x] T025 [P] [US1] Write test `tests/Contract/Runbook/ConsoleExitCodeTest.php`: all ok or warn gives exit 0; an unregistered name gives exit 2 and prints only the registered names; a timeout or error step gives exit 1
- [x] T026 [P] [US1] Write test `tests/Contract/Runbook/ConsoleNoReceiptTest.php`: with `tools.runbook.disk.enabled` false the command still runs (FR-013); no receipt is written (R3); an `error` step writes one operator note with the exception class, file basename and line and no exception message (FR-015, constitution VII)
- [x] T027 [US1] Create `src/Console/RunbookCommand.php` (`gate:runbook {name}`) and register it in `src/ContextGateServiceProvider.php`; it uses RunbookRegistry and RunbookRunner, prints the rows and sets the exit code
- [x] T028 [P] [US1] Write test `tests/Contract/Runbook/ConsoleCanaryTest.php`: a step text that contains a configured canary value prints `Output withheld: a canary value was found.` and exits 1 (FR-010, FR-013)
- [x] T029 [US1] Edit `src/Console/RunbookCommand.php` to pass each row's text through the existing text stages (`src/Pipeline/*`, the same ones `src/Server/FirewallRunner.php` uses; R7) before printing

**Checkpoint**: US1 is independently testable through `gate:runbook`.

---

## Phase 4: User Story 2 - Ask for the same report through MCP (Priority: P1)

**Goal**: `runbook.<name>` lists and answers like any source tool: rows marked untrusted, canary-checked, receipted, hidden
when disabled.

**Independent Test**: Enable the tool, call it; rows match the console statuses. Disable it; the response equals the unknown-tool response.

### Tests for User Story 2

- [x] T030 [P] [US2] Write test `tests/Contract/Runbook/RunbookToolListTest.php`: `runbook.disk` appears in `tools/list` for a caller that lists it when enabled, and is absent when disabled (FR-019, constitution III)
- [x] T031 [P] [US2] Write test `tests/Contract/Runbook/RunbookHiddenTest.php`: a call to a disabled `runbook.disk` returns the same response as an unknown tool name, and the receipt records `policy.hidden` (spec 007 US3)
- [x] T032 [P] [US2] Write test `tests/Contract/Runbook/RunbookRowsTest.php`: the rows equal the console statuses for the same environment (SC-005); the response has `untrusted: true` and `source: runbook.disk` (FR-012); `dropped_rows` is present; the receipt holds the runbook name and status counts and no row text (FR-022)
- [x] T033 [US2] Create `src/Source/RunbookTool.php` (source tool for `runbook.<name>`, one per registered runbook, following the spec 003 source tool pattern) and register the tools where the source tools are registered (FR-002)
- [x] T034 [P] [US2] Write test `tests/Contract/Runbook/RunbookCeilingTest.php`: the tool ceiling is `internal` (FR-011); a caller whose ceiling is `public` does not receive the report and gets the unknown-tool response; a caller with `internal` receives it (spec 002 FR-007)
- [x] T035 [US2] Edit `src/Source/RunbookTool.php` to declare classification and ceiling `internal` and to pass the report through the firewall stages and receipt (FR-010, FR-022); a canary hit returns the generic failure and records `canary.hit`
- [x] T036 [P] [US2] Write test `tests/Contract/Runbook/RunbookCanaryBlockTest.php`: a canary value in a step text blocks the MCP response with the generic failure and records `canary.hit` (SC-004, spec 002 US5)

---

## Phase 5: User Story 3 - Steps cannot write or reveal values (Priority: P1)

**Goal**: A step has only the read context; no value leaves a step; the runbook code has no write call; a step that
reads a secret reports `true` only.

**Independent Test**: Public method set of the context equals FR-004; seeded env value appears in no output.

### Tests for User Story 3

- [x] T037 [P] [US3] Write test `tests/Contract/Runbook/ReadOnlySourceScanTest.php`: scans `src/Runbook/*.php` and `src/Source/RunbookTool.php` and fails on any call to a write-like method name (`dispatch`, `put`, `forget`, `increment`, `insert`, `update`, `delete`, `send`, `post`, `call`, `artisan`, `save`), listing the file and line (FR-005, R5)
- [x] T038 [P] [US3] Write test `tests/Contract/Runbook/NoValueInOutputTest.php` (SC-002): seed `APP_KEY`, a config value, a cache value and a database row with fixture values; run a runbook that checks each by name; none of the values appears in console or MCP output (FR-009); the step receives `true` for `APP_KEY` (US3 AC2)
- [x] T039 [P] [US3] Write test `tests/Contract/Runbook/ReadOnlyCheckTest.php`: a runbook whose step declares `readOnly` false makes `gate:check` fail and name the runbook and the step (US3 AC3, FR-006); `runbook.<name>` is refused on every MCP call while it fails (SC-003)

---

## Phase 6: User Story 4 - A changed runbook needs re-approval (Priority: P2)

**Goal**: The step list is in the fingerprint; a change makes the tool refuse until `gate:fingerprint` refreshes it.

**Independent Test**: Add a step; with the old approved fingerprint, the tool is refused.

### Tests for User Story 4

- [ ] T040 [P] [US4] Write test `tests/Contract/Runbook/RunbookFingerprintRefusalTest.php`: with an approved fingerprint from the old step list, adding a step makes `gate:check` fail for `runbook.<name>` and the tool refuse every call until `gate:fingerprint runbook.<name>` runs (FR-020, spec 002 Amendments)
- [ ] T041 [US4] Edit `src/Source/RunbookTool.php` to compare the computed fingerprint with the approved value in config and refuse on mismatch, with the same generic response as spec 002 for a fingerprint refusal

---

## Phase 7: User Story 5 - `gate:check` validates runbooks (Priority: P2)

**Goal**: `gate:check` lists every runbook and fails for any that is invalid, naming the key and the rule.

**Independent Test**: One valid and one invalid runbook; the first passes, the second fails and is named.

### Tests for User Story 5

- [ ] T042 [P] [US5] Write test `tests/Unit/Readiness/Checks/RunbookCheckTest.php`: a valid runbook gives PASS; a missing class gives FAIL with subject `runbooks.<key>`; 21 steps gives FAIL naming the runbook; a duplicate or case-only duplicate name gives FAIL; a failing fingerprint gives FAIL naming `runbook.<name>`
- [ ] T043 [US5] Create `src/Readiness/Checks/RunbookCheck.php` implementing `Check` (wraps RunbookValidator and RunbookFingerprint) and register it in `src/Readiness/CheckRegistry.php` (spec 006)
- [ ] T044 [P] [US5] Write test `tests/Contract/Runbook/CheckCommandRunbookTest.php`: `gate:check` prints one result per registered runbook, exits 1 with an invalid runbook and passes the valid one (US5 AC3); this is a wiring test, expected green after T043

---

## Phase 8: Polish and Cross-Cutting

- [ ] T045 Confirm the spec 002 privacy suite (`tests/Contract/PrivacySuiteTest.php`) and the spec 006 check registry test still pass with the new check, command and source tool
- [ ] T046 Confirm every new file under `src/Runbook/`, `src/Console/RunbookCommand.php`, `src/Source/RunbookTool.php` and `src/Readiness/Checks/RunbookCheck.php` stays within the limit enforced by `scripts/check-file-length.php`
- [ ] T047 Update `docs/` with the content of `quickstart.md` (runbook section) after the feature is merged; docs only, no test
- [ ] T048 Run `composer test`, `composer stan` (level 8), `composer style` and `composer length`; read each exit code; all must be 0

---

## Dependencies and Execution Order

- Phase 1 before everything. X001 (T006) before T004. X002 (T007) before T033.
- Phase 2 before all stories. Within Phase 2, each test task runs and fails before its implementation starts.
- Phases 3 to 7 are independent of each other once Phase 2 is green, except: US2 (T033) needs the runner (T017); US4
  (T041) needs US2's tool (T033); US5 (T043) needs T021 and T023.
- Within a story, each test task (`T0xx` before its implementation) runs and fails before the implementation starts.
- Tasks marked **[P]** touch different files and may run together.
- Phase 8 last. T048 is the hand-off gate.

## Count

48 tasks: Setup 7 (T001 to T007), Foundational 16 (T008 to T023), US1 6 (T024 to T029), US2 7 (T030 to T036), US3 3
(T037 to T039), US4 2 (T040 to T041), US5 3 (T042 to T044), Polish 4 (T045 to T048).
