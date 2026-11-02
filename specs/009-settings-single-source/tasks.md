# Tasks: One Path to Settings

**Input**: Design documents from `specs/009-settings-single-source/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R8), data-model.md, contracts/classification-config.md.
Spec 011 gates in place (mutation and coverage jobs guard this refactor). Spec 010 starts after this spec.

**Tests**: REQUIRED (constitution IX). Every implementation task is preceded by a failing test. Tasks marked
*characterization* lock existing behaviour before a move; they pass when written.

**Organization**: by user story. US1, US2, US3 are P1; US4 is P2.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup

- [x] T001 [P] Write *characterization* test `tests/Contract/Settings/SourceToolColumnsTest.php`: with the current shipped config, each source tool (`routes.list`, `config.keys`, `schema.describe`, `docs.read`, `openapi.endpoints`, `runbook.<name>`) returns its columns classified `internal` (baseline for SC-004)
- [x] T002 [P] Write *characterization* test `tests/Contract/Settings/LimitConsumersTest.php`: under strict and balanced, Budget, RefusalLedger, docs.read and the OpenAPI source use the profile values (baseline for US2 scenario 1)

---

## Phase 2: Foundational — the typed path

**Checkpoint**: `ActiveSettings` exposes every getter of research R2; nothing consumes them yet.

- [x] T003 [P] Write test `tests/Unit/Settings/SettingsSchemaTypesTest.php`: for every key of research R6, a wrong type (string for list, list for string, empty string for `string+`, non-string list item, `examples` = `keep`) is reported with its key; correct types pass
- [x] T004 Edit `src/Settings/SettingsSchema.php`: add leaf types `string+`, `list<string+>`, `map<string,string>`; replace every `any` per research R6 (`classification` delegated to T006)
- [x] T005 [P] Write test `tests/Unit/Settings/ClassificationMergeTest.php`: empty host gives the six built-in tables; `source_docs => false` removes the table; `['middleware' => false]` removes one column; repeating a built-in table exactly equals omitting it; another class on a built-in column, a new column on a built-in table, an unknown `source_x` table and a host class outside the four classes each give one violation with the key of contracts/classification-config.md; host tables are added unchanged
- [x] T006 Create `src/Settings/BuiltinClassification.php` and `src/Settings/ClassificationMerge.php` (`effective(array $host): array`, `violations(mixed $host): list`); call `violations` from `src/Settings/SettingsValidator.php`
- [x] T007 [P] Write test `tests/Unit/Settings/ActiveSettingsTypedTest.php`: each getter of research R2 returns the typed value of a valid document; `limits()` equals `EffectiveSettings::for()`; on an invalid document every typed getter throws `InvalidSettings` except `channel()` (returns `context-gate`), `valid()`, `violations()`, `document()`, `environment()`; the document is validated once per instance
- [x] T008 Create `src/Settings/InvalidSettings.php`, `src/Settings/ToolEntry.php`; extend `src/Settings/ActiveSettings.php` with the getters (split source getters into `src/Settings/SourceSettings.php` if `composer length` requires)
- [x] T009 [P] Write test `tests/Unit/Settings/EffectiveSettingsTest.php` additions: an above-cap explicit value is not silently replaced by the profile value any more (the document is invalid; `for()` is only called on valid documents and returns the explicit value)
- [x] T010 Edit `src/Settings/EffectiveSettings.php`: drop the above-cap fallback in `for()` (FR-007); keep the static readers until Phase 4 removes their callers

---

## Phase 3: User Story 3 - Built-in source tables cannot be dropped or reclassified (P1)

**Independent Test**: host classification without source tables; source tools still return `internal` columns.

- [ ] T011 [P] [US3] Write test `tests/Contract/Settings/BuiltinSourceTablesTest.php`: with `classification` = host tables only, T001's expectations hold (SC-004); `source_docs => false` makes `docs.read` return no columns and the receipt list `field.unlisted` per dropped column; `source_routes.uri => 'public'` makes `gate:check` exit 1 naming `context-gate.classification.source_routes.uri` and a call refused with `config.invalid`; a published file that repeats the old tables passes
- [ ] T012 [US3] Edit `src/Server/FirewallRunner.php:67`, `src/Server/ContractGuard.php:26`, `src/Server/Sources/SchemaDescribeTool.php:46`, `src/Server/GateServer.php:126`, `src/Console/CheckCommand.php:44`, `src/Console/AuditCommand.php:31` to read `ActiveSettings::classification()`
- [ ] T013 [US3] Edit `config/context-gate.php`: `'classification' => []`, comment explains host tables and hiding, "keep these entries" removed; update `tests/Unit/Settings/ConfigDefaultsTest.php` expectation first

---

## Phase 4: User Story 2 - The profile and caps apply everywhere (P1)

**Independent Test**: the architecture test fails on any direct read outside `src/Settings/`.

- [ ] T014 [US2] Write test `tests/Unit/Settings/SingleSettingsPathTest.php` (research R7): no `config('context-gate…')`, `config()->get('context-gate…')`, `Config::get('context-gate…')` or `EffectiveSettings::` call outside `src/Settings/`; failure lists file:line. Expected red with the research R1 list
- [ ] T015 [P] [US2] Move reads in `src/Server/`: `Caller.php:25,48`, `Canaries.php:20`, `Failure.php:24`, `GateCallTool.php:49`, `GateListTools.php:23,39` (return no tools when `valid()` is false), `GateServer.php:64,90,129,149`, `PolicyStep.php:36`, `Receipt.php:79`, `Simulator.php:48,68`, `Tool.php:53`, `ToolRegistry.php:78`
- [ ] T016 [P] [US2] Move reads in `src/Server/Sources/`: `DocsReadTool.php:52,59`, `OpenApiEndpointsTool.php:68,74,75-76`, `SchemaDescribeTool.php:49`
- [ ] T017 [P] [US2] Move reads in `src/Http/GateAuthRefusal.php:51`, `src/Http/GateOriginCheck.php:30`, `src/Http/HttpRoute.php:51`, `src/Http/RefusalLedger.php:48`, `src/Pipeline/Budget.php:44` (also drop its own `min(..., Caps::…)`; the effective value is already capped, FR-007), `src/Policy/ToolPolicy.php:18`, `src/Support/GateCache.php:20`
- [ ] T018 [P] [US2] Move reads in `src/Source/ConfigSource.php:27`, `src/Source/RoutesSource.php:32`, `src/Runbook/RunbookRegistry.php:24`
- [ ] T019 [P] [US2] Move reads in `src/Console/`: `AuditCommand.php:40,41`, `CheckCommand.php:86,100,119,132,136,142,148`, `ClassifyCommand.php:38`, `FingerprintCommand.php:21`, `ScanCommand.php:32`, `SimulateCommand.php:33`, `TokenCommand.php:87,166`, `ConfigExportCommand.php`, `ConfigImportCommand.php`, `ConfigRollbackCommand.php` (environment via `ActiveSettings::environment()`)
- [ ] T020 [US2] Add `ConfigOverlay::install(Application $app)` in `src/Settings/ConfigOverlay.php` (test first in `tests/Unit/Settings/ConfigOverlayTest.php`: same document and overlay violations as today) and use it in `src/ContextGateServiceProvider.php:134-140`
- [ ] T021 [US2] Remove `EffectiveSettings::current()`, `limit()`, `ceiling()`, `examples()`, `document()`, `environment()`; T014 green; T002 still green (US2 scenario 1)

---

## Phase 5: User Story 1 - `gate:check` and the runtime agree (P1)

- [ ] T022 [P] [US1] Write test `tests/Contract/Settings/CheckRuntimeAgreeTest.php`: data provider with one invalid document per schema key (SC-002); for each, `gate:check` exits 1 naming the key and a `tools/call` is refused with `config.invalid` and `tools/list` is empty; for three valid documents (strict, balanced, explicit limits) `gate:check --json` `effective` equals `ActiveSettings::limits()` and `ceiling()` (SC-003)
- [ ] T023 [US1] Edit `src/Console/CheckCommand.php:54` to build `ReadinessContext` from `ActiveSettings::document()` and add the `effective` JSON field; edit `src/Readiness/Checks/SettingsCheck.php` to report `ActiveSettings::violations()` (plus the overlay violations it already merges)

---

## Phase 6: User Story 4 - Receipts prove which values were in force (P2)

- [ ] T024 [P] [US4] Write test `tests/Unit/Settings/RuleHashesTest.php`: tool and contract hashes equal today's `ContextGateServiceProvider::ruleHashes()` output (characterization); classification hash differs between strict and balanced, between ceilings `public` and `internal`; equal for a repeated vs omitted built-in table (SC-006)
- [ ] T025 [US4] Create `src/Settings/RuleHashes.php` (moved from `src/ContextGateServiceProvider.php:69-125`, classification hash per ceiling); remove the `context-gate.rules` singleton
- [ ] T026 [P] [US4] Write test `tests/Contract/Settings/RuleHashEffectiveTest.php`: over HTTP, tokens with ceiling `public` and `internal` calling the same tool get different `rules.classification`; over stdio the configured ceiling is used
- [ ] T027 [US4] Edit `src/Server/ResponseBuilder.php:24` to use `RuleHashes::forCall(Caller::ceiling())`

---

## Phase 7: Polish

- [ ] T028 Update `docs/configuration.md`: merge rule, hiding with `false`, reserved `source_` prefix, the six built-in tables, `gate:check --json` `effective` (FR-015)
- [ ] T029 [P] Update `CHANGELOG.md` Unreleased: built-in source tables in code, typed settings validation, rule hash covers the call ceiling
- [ ] T030 Run `composer style`, `composer stan`, `composer length`, `composer refs`, `composer test`; read each exit code; all 0. Run `composer mutation` and `composer coverage` (spec 011) and confirm they stay at or above their thresholds

---

## Dependencies and Execution Order

- Phase 1 first (baselines). Phase 2 before all stories. T003→T004, T005→T006, T007→T008, T009→T010.
- US3 (Phase 3) before US2's final step T021 (classification getter must exist). T014 is written before T015–T020 and
  turns green at T021.
- US1 after Phase 4. US4 after Phase 2; T024→T025→T027, T026 before T027.
- T015–T019 touch different directories and may run in parallel.
