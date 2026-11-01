# Tasks: Configuration and Policy Management (MVP)

**Input**: Design documents from `specs/007-configuration/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R8), data-model.md. Specs 001–006 must be complete. Decisions
R1, R4, R5 and R6 must be confirmed by the maintainer before the tasks that depend on them (marked **[DECIDE]**).

**Tests**: REQUIRED (constitution IX). Every implementation task is preceded by a test task that must fail first.
Tasks marked *characterization* lock behaviour that already exists in specs 001–003; their test is expected to pass
when written and guards against regression. No test may touch the network.

**Organization**: Grouped by user story. US1, US2 and US3 are P1 (MVP core). US4 and US5 are P2 (limits and logging).
US6 is P2 (versions). US7 is P3 (simulator).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1…US7 from spec.md
- Paths are relative to the repository root

---

## Phase 1: Setup

- [x] T001 Write test `tests/Unit/Settings/ConfigDefaultsTest.php`: `config/context-gate.php` has `profile` = `strict`, `callers` = `[]`, `log_level` = `info`, `sources.openapi.access` = `read`, and the new limit keys null; it has no `redaction` key and no key naming a stage
- [x] T002 Edit `config/context-gate.php` to add those keys with their defaults and a comment block for each (research R2, R6; data-model Setting keys)
- [x] T003 [P] Write test in `tests/Unit/Pipeline/CapsTest.php`: `Caps::OPENAPI_TIMEOUT` is `5.0` and `Caps::OPENAPI_BYTES` is `2097152` (values as in `PinnedClient` and `OpenApiParser` today)
- [x] T004 Edit `src/Pipeline/Caps.php` to add `OPENAPI_TIMEOUT` and `OPENAPI_BYTES` public constants
- [x] T005 Edit `src/Source/Fetch/PinnedClient.php` and `src/Source/OpenApiParser.php` to read `Caps::OPENAPI_TIMEOUT` and `Caps::OPENAPI_BYTES` instead of their private constants. No behaviour change; the existing suite is the test

---

## Phase 2: Foundational

**Purpose**: Profiles, environment rule, schema and the one validator used by `gate:check`, import, rollback and boot.

**Checkpoint**: validator and profiles are green. Every user story below calls them; no story adds its own rule.

- [x] T006 [P] Write test `tests/Unit/Settings/ProfilesTest.php`: `SecurityProfile` has exactly `strict` and `balanced`; both value maps have the same keys (the cap keys of research R2); every strict value is at most its balanced value, and every balanced value is at most its cap; balanced equals the caps
- [x] T007 Create `src/Settings/SecurityProfile.php` (backed enum) and `src/Settings/Profiles.php` (`for(SecurityProfile $p): array` returning the value maps of research R2)
- [x] T008 [P] Write test `tests/Unit/Settings/EnvironmentProfileTest.php`: `production`, an unknown value and an empty string resolve to strict whatever is configured; `local`, `development`, `staging` and `testing` resolve to the configured profile; no configured profile resolves to strict
- [x] T009 Create `src/Settings/EnvironmentProfile.php` with `resolve(string $env, ?string $configured): SecurityProfile`
- [x] T010 [P] Write test `tests/Unit/Settings/SettingsSchemaTest.php`: every key of data-model Setting keys is allowed with its type; an unknown key is reported by name; `redaction` is reported with the message that redaction cannot be disabled; any key naming a stage is reported; `sources.openapi.access` = `write` is reported; `log_level` accepts `debug`, `info`, `notice`, `warning`, `error` and rejects `off`
- [x] T011 Create `src/Settings/SettingsSchema.php` (allowed keys, types, enum values, forbidden keys with messages)
- [x] T012 [P] Write test `tests/Unit/Settings/SettingsValidatorTest.php`: one call with an unknown key, a cap violation and a weak profile in production returns all three violations with their keys (FR-011); an empty document is valid; a limit equal to its cap passes and one above fails; `profile` = `balanced` with `APP_ENV` production fails naming `profile`; `callers.a.tools` naming a tool absent from `tools` fails naming `callers.a.tools`; the cap map covers the two new `sources.openapi` keys
- [x] T013 Edit `src/Config/CapsValidator.php` to add `sources.openapi.timeout_seconds` (`Caps::OPENAPI_TIMEOUT`) and `sources.openapi.max_bytes` (`Caps::OPENAPI_BYTES`) to its cap map; add `public static function caps(): array` returning the map
- [x] T014 Create `src/Settings/SettingsValidator.php`: `violations(array $document, string $env, array $caps = CapsValidator::caps()): list<array{key: string, message: string}>`, combining SettingsSchema, the cap map, EnvironmentProfile and the callers and tools cross-check
- [x] T015 [P] Write test `tests/Unit/Settings/EffectiveSettingsTest.php`: an explicit valid value wins over the profile value; `null` takes the profile value; in production the strict values apply even when balanced is configured; a per-tool value that is `null` takes the effective global value
- [x] T016 Create `src/Settings/EffectiveSettings.php` with `for(array $document, string $env): array` returning every limit key with its effective value
- [x] T017 [P] Write test `tests/Unit/Settings/ActiveSettingsTest.php`: a valid document gives `valid()` true and no violations; an invalid document gives `valid()` false and the violation list; the result is computed once per instance
- [x] T018 Create `src/Settings/ActiveSettings.php` wrapping SettingsValidator for the document in config (no caching outside the instance)

---

## Phase 3: User Story 1 - Run each environment with its own profile (Priority: P1)

**Goal**: Production forces strict; a weaker profile in production fails `gate:check` and names `profile`.

**Independent Test**: `APP_ENV=production` with `profile` = `balanced` makes `gate:check` exit 1 and name `context-gate.profile`.

### Tests for User Story 1

- [x] T019 [P] [US1] Write test `tests/Unit/Readiness/Checks/SettingsCheckTest.php`: production with balanced gives FAIL with subject `context-gate.profile`; production with no profile gives PASS; development with no profile gives PASS; the fix hint names the profile key
- [x] T020 [US1] Create `src/Readiness/Checks/SettingsCheck.php` implementing `Check`; it runs SettingsValidator on `ReadinessContext::$config` and lists every violation key in one FAIL; register it in `src/Readiness/CheckRegistry.php`
- [x] T021 [P] [US1] Write test `tests/Contract/Settings/CheckCommandProfileTest.php`: `gate:check` exits 1 and prints `context-gate.profile` with `APP_ENV=production` and `profile` = `balanced`; exits without that line with `APP_ENV=development` and no profile (wiring check; expected green after T020)

**Checkpoint**: US1 is independently testable through `gate:check`.

---

## Phase 4: User Story 2 - Choose a security profile within code caps (Priority: P1)

**Goal**: Strict is the default; balanced is within caps; stages and order never change; a limit above cap fails.

**Independent Test**: Same stage list under both profiles; `per_call_bytes` = 70000 fails `gate:check` and names the key.

### Tests for User Story 2

- [x] T022 [P] [US2] Write test `tests/Contract/Settings/ProfileStagesTest.php` (characterization): the pipeline stage list built by the existing firewall code is identical whether `profile` is strict or balanced, and does not read any configuration key
- [x] T023 [US2] Write test `tests/Contract/Settings/CheckCapsTest.php`: `firewall.limits.per_call_bytes` = 70000 makes `gate:check` exit 1 and name the key; 65536 passes; two violations in one run are both named (FR-011)
- [x] T024 [US2] Edit `src/Readiness/Checks/CapsCheck.php` to name every violating key in one FAIL, not the first one, and to use the extended cap map from T013
- [x] T025 [P] [US2] Write test `tests/Contract/Settings/BootValidationTest.php`: with an invalid document, a `tools/call` is refused with `config.invalid` and its receipt records `config.invalid`; with a valid document the existing response is unchanged (research R8)
- [x] T026 [US2] Edit `src/ContextGateServiceProvider.php` to build `ActiveSettings` at boot, and `src/Server/GateCallTool.php` to refuse with `ReasonCode::ConfigInvalid` when it is invalid, before the budget is admitted

**Checkpoint**: US2 works with file config only; overlay (US6) is not needed yet.

---

## Phase 5: User Story 3 - Enable tools per caller (Priority: P1)

**Goal**: Each caller sees only its listed tools; configuration checks the names.

**Independent Test**: Two callers with different lists; an unlisted tool answers as unknown and the receipt records `policy.hidden`.

### Tests for User Story 3

- [x] T027 [P] [US3] Write test `tests/Contract/Settings/CallerToolsTest.php` (characterization of spec 001): caller A lists tool X and B does not; B's list omits X; B's call to X gives the same response as an unknown tool and records `policy.hidden`
- [x] T028 [US3] Write test `tests/Unit/Settings/SettingsValidatorCallersTest.php`: a caller with a tool missing from `tools` fails naming `callers.<id>.tools`; an unknown key under `callers.<id>` fails; an empty list passes
- [x] T029 [US3] Edit `src/Settings/SettingsSchema.php` and `src/Settings/SettingsValidator.php` to add the callers rules tested in T028

---

## Phase 6: User Story 4 - Set limits for connectors and tools (Priority: P2)

**Goal**: Connector timeout and size, and per-tool size and rate, may only be lower than their caps. Connector access is read-only.

**Independent Test**: `sources.openapi.timeout_seconds` = 2.5 is used by the next fetch; a value above the cap fails `gate:check`.

### Tests for User Story 4

- [x] T030 [P] [US4] Write test `tests/Unit/Source/PinnedClientTimeoutTest.php`: `PinnedClient` uses an effective timeout of 2.5 when given; with no value it uses `Caps::OPENAPI_TIMEOUT`; a value above the cap is clamped to the cap
- [x] T031 [US4] Edit `src/Source/Fetch/PinnedClient.php` and `src/Source/OpenApiParser.php` to take the effective timeout and size from `EffectiveSettings`, clamped to the caps; the call site that builds them passes the values
- [ ] T032 [P] [US4] (**dropped, review round 2, 2026-11-01**: per-tool limits were never implemented and are not supported) Write test `tests/Contract/Settings/ToolLimitsTest.php`: `tools.<name>.max_bytes` lower than the per-call value truncates that tool's response and sets `truncated`; `tools.<name>.calls_per_minute` refuses the call after the limit with `rate.limited` while another tool is unaffected; a per-tool value above the effective global fails `gate:check`
- [ ] T033 [US4] (**dropped, review round 2, 2026-11-01**: deferred; the settings schema rejects per-tool limit keys) Edit `src/Pipeline/Budget.php` to take an optional per-tool minute limit keyed by caller and tool name, and `src/Server/GateCallTool.php` to pass the tool's limit to it (**[DECIDE]** R4: no session TTL setting)

---

## Phase 7: User Story 5 - Control logging and privacy (Priority: P2)

**Goal**: Redaction stays on; logs never hold values; `log_level` is validated.

**Independent Test**: A sentinel in arguments and results is absent from every `context-gate` log line; a config with `redaction` fails.

### Tests for User Story 5

- [x] T034 [P] [US5] Write test `tests/Contract/Settings/LogPrivacyTest.php` (characterization of spec 001 receipts): a call with a sentinel argument and a sentinel result writes receipt lines to the `context-gate` channel that do not contain the sentinel (SC-005)
- [x] T035 [P] [US5] Write test `tests/Contract/Settings/RedactionLockTest.php`: `gate:check` exits 1 and names `redaction` when the file config sets it to false; exits 1 and names `log_level` when it is `off` (logic from T011 and T014; expected green after them). **[DECIDE]** R6: no metadata toggle, `log_level` has no consumer yet

---

## Phase 8: User Story 6 - Validate, export, import and roll back (Priority: P2)

**Goal**: Import validates the whole file; export has no secrets; versions have hashes; rollback is refused when a cap has decreased.

**Independent Test**: Invalid import changes nothing; valid import then rollback makes the earlier hash active.

### Tests for User Story 6

- [x] T036 [P] [US6] Write test `tests/Unit/Settings/ConfigExporterTest.php`: export omits `canaries.values`; the output contains no canary value and no hash of one; no key matches the secret pattern; the output passes SettingsValidator (round trip)
- [x] T037 [US6] Create `src/Settings/ConfigExporter.php` with `export(array $document): array`
- [x] T038 [P] [US6] Write test `tests/Unit/Settings/ConfigVersionStoreTest.php` (temporary storage path): the hash does not depend on key order; the same document gives the same hash and no second index entry; each entry's `previous` is the prior active hash; the index keeps at most 50 entries; an unreadable index refuses to apply and is not overwritten
- [x] T039 [US6] Create `src/Settings/ConfigVersionStore.php` with `save`, `active`, `find`, `previous` and `rollback(string $hash, SettingsValidator $validator)`, writing files with temp file and rename
- [x] T040 [P] [US6] Write test `tests/Unit/Settings/ConfigAuditTest.php`: a `config.applied` line has `event`, `actor`, `at`, `changed_keys`, `old_hash`, `new_hash`; a sentinel in the old and new documents is absent from the line; a changed canary is recorded as the key name `canaries.values` only
- [x] T041 [US6] Create `src/Settings/ConfigAudit.php` writing to `Log::channel(config('context-gate.channel'))` (research R7)
- [x] T042 [P] [US6] Write test `tests/Contract/Settings/ImportTest.php`: an invalid file (unknown key, cap violation, weak profile with `APP_ENV=production`) makes `gate:config:import` exit 1, print every error, and leave the index and the version directory unchanged (SC-004); a valid file exits 0, sets the active hash, and writes one audit line
- [x] T043 [US6] Create `src/Settings/ConfigImporter.php` (parse JSON, validate the whole document, store, audit) and `src/Console/ConfigImportCommand.php` (`gate:config:import {file}`); register the command in `src/ContextGateServiceProvider.php`
- [x] T044 [P] [US6] Write test `tests/Contract/Settings/ExportCommandTest.php`: `gate:config:export` exits 0 and its output contains no canary value and no secret key
- [x] T045 [US6] Create `src/Console/ConfigExportCommand.php` (`gate:config:export`) using ConfigExporter; register it
- [x] T046 [P] [US6] Write test `tests/Contract/Settings/RollbackTest.php`: after versions A then B, `gate:config:rollback <A>` makes A active and writes a `config.rollback` audit line; an unknown hash exits 1; a version file written directly with a value above the current cap makes rollback exit 1, naming the key, and leaves the active hash unchanged (FR-014)
- [x] T047 [US6] Create `src/Console/ConfigRollbackCommand.php` (`gate:config:rollback {hash}`) using `ConfigVersionStore::rollback`; register it
- [x] T048 [P] [US6] Write test `tests/Unit/Settings/ConfigOverlayTest.php`: with an active version, `ConfigOverlay::apply(array $fileConfig, ConfigVersionStore $store)` returns the version document over the file config; with no active version the file config is returned unchanged; an active version that fails validation makes `ActiveSettings` invalid (**[DECIDE]** R1)
- [x] T049 [US6] Create `src/Settings/ConfigOverlay.php` and call it in `src/ContextGateServiceProvider.php` before `ActiveSettings` is built

**Checkpoint**: US6 works end to end through the three artisan commands.

---

## Phase 9: User Story 7 - Simulate a decision before applying a rule (Priority: P3)

**Goal**: Report the decision for a call without calling the handler; receipt marked simulated.

**Independent Test**: Hidden tool gives `hidden`, visible valid call gives `allow`, handler call count stays 0.

### Tests for User Story 7

- [x] T050 [P] [US7] Write test `tests/Unit/Server/PolicyStepTest.php` (characterization before the extraction): `PolicyStep` gives the same reason for unknown, hidden, pin mismatch, invalid input and denied policy as `GateCallTool` gives today, and no reason for an allowed call
- [x] T051 [US7] Create `src/Server/PolicyStep.php` by moving the visibility and policy checks out of `src/Server/GateCallTool.php`; `GateCallTool` calls it. No behaviour change
- [x] T052 [P] [US7] Write test `tests/Unit/Server/ReceiptSimulatedTest.php`: `Receipt::write(..., simulated: true)` adds `"simulated": true`; without the argument the record has no `simulated` key (**[DECIDE]** R5: spec 001 receipt format change must be written into the spec first)
- [x] T053 [US7] Edit `src/Server/Receipt.php` to add the optional `?bool $simulated = null` parameter, written only when true
- [x] T054 [P] [US7] Write test `tests/Contract/Settings/SimulatorTest.php`: with a fake handler that counts calls (`tests/Fakes`), `gate:simulate` on a hidden tool reports `hidden` with the handler count at 0 and a simulated receipt; on a visible tool with valid arguments reports `allow`; the decisions equal those of a real call for the same inputs (SC-003); a simulation does not consume the per-minute budget
- [x] T055 [US7] Create `src/Server/Simulator.php` (PolicyStep plus argument validation, no handler, no budget admit) and `src/Console/SimulateCommand.php` (`gate:simulate {tool} {caller} {--args=}`); register the command

---

## Phase 10: Polish and Cross-Cutting

- [x] T056 Update `docs/configuration.md` (placeholder from spec 006) with the keys, profiles, environment rule, import, export, rollback and simulator. Docs only; no test
- [x] T057 Run `composer test`, `composer stan`, `composer style` and `composer length`; read each exit code; all must be 0
- [x] T058 Confirm `src/Server/GateCallTool.php` and every new `src/Settings/*.php` file stay within the limit enforced by `scripts/check-file-length.php`
- [x] T059 Confirm the spec 002 privacy suite (`tests/Contract/PrivacySuiteTest.php`) and the spec 006 check registry test still pass with the new checks and commands
- [x] T060 Update the spec 007 Assumptions in `specs/007-configuration/spec.md` to match R1 (overlay), R4 (no cache TTL) and R6 (no metadata toggle), once the maintainer has decided them

---

## Dependencies and Execution Order

- Phase 1 before everything. Phase 2 before all stories. Phases 3 to 9 are independent of each other once Phase 2
  is green, except US6 (overlay T049) which US2's boot path (T026) uses in its final form.
- Within a story, each test task (`T0xx` before its implementation) runs and fails before the implementation starts.
- Tasks marked **[P]** touch different files and may run together.
- Decisions R1, R4, R5 and R6 gate T033, T035, T048, T052. Do not start those tasks before the maintainer confirms.
