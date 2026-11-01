# Tasks: Row Scopes per Caller

**Input**: Design documents from `specs/014-row-scopes/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R11), data-model.md, contracts/scope-resolver.md. Spec 010
(caller roles) should be merged first (research R11); nothing below reads `callers` or roles, so the tasks also apply on
today's `Caller`. Spec 012 must not enable a generated tool on scoped data before Phase 6 of this file is done.

**Tests**: REQUIRED (constitution IX). Every implementation task is preceded by a test task that must fail first. No
test touches the network. Sentinel scope values use the prefix `FAKE_FIXTURE_ONLY_SCOPE_`.

**Organization**: by user story. US1, US2, US3 are P1. US4 is P2. US5 is P3.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1…US5 from spec.md
- Paths are relative to the repository root

---

## Phase 1: Setup

- [ ] T001 [P] Write test in `tests/Unit/Pipeline/CapsTest.php`: `Caps::SCOPE_VALUES` is 100, `Caps::SCOPE_NAMES` is 4, `Caps::SCOPE_TIMEOUT_MS` is 2000
- [ ] T002 Add the three constants to `src/Pipeline/Caps.php`
- [ ] T003 [P] Create fixtures: `tests/Fixtures/ScopedEntryRecord.php` (model on table `scoped_entries`: id, federation_id, event_id, status, athlete_id), `tests/Fixtures/ScopedAthleteRecord.php` (table `scoped_athletes`: id, federation_id, name), `tests/Fixtures/FakeScopes.php` (a `ResolvesScopes` returning a grant map set by the test, optionally throwing or sleeping), and a migration helper in `tests/Fixtures/ScopedTables.php` that creates and seeds both tables for federations F and G

---

## Phase 2: Foundational

**Purpose**: the declaration, the grant and resolution types, the reason codes and the receipt field. Every story uses them.

**Checkpoint**: unit tests for `Scope`, `ScopeGrant`, `CallScope`, `ScopeResolution`, `ReasonCode` and `Receipt` are green.

- [ ] T004 [P] Write test `tests/Unit/Scope/ScopeTest.php`: `Scope::by(['federation' => 'federation_id'])` keeps the map; `Scope::none()` is unscoped with no names; a name outside `/^[a-z][a-z0-9_]{0,63}$/`, an empty map and more than `Caps::SCOPE_NAMES` names throw; `canonical()` is key-sorted, or `"none"`
- [ ] T005 Create `src/Scope/Scope.php`
- [ ] T006 [P] Write test `tests/Unit/Scope/ScopeGrantTest.php`: `values([1, 'a'])` is restricted; `values([])` throws; a value that is null, a float, a bool, an array, an empty string or a string over 191 characters throws; `all()` has mode `all`; there is no other constructor
- [ ] T007 Create `src/Scope/ScopeGrant.php`
- [ ] T008 [P] Write test `tests/Unit/Scope/CallScopeTest.php`: `admits()` passes a row whose column value is in the values (int 12 matches '12'), fails a row with the column missing, null, an array, or a foreign value; with two names a row must pass both; mode `all` admits any row for that name; `apply()` adds one `whereIn` per restricted name and none for `all` (assert on the builder's wheres); `bindingKey()` is stable for the same grants in any order and differs when one value changes; `summary()` lists name, mode and removed and holds no value
- [ ] T009 Create `src/Scope/CallScope.php` with `current()`, `admits()`, `apply()`, `bindingKey()`, `summary()`, `countRemoved()`
- [ ] T010 [P] Write test `tests/Unit/Scope/ScopeResolutionTest.php`: every outcome of research R2 table: grant for every name gives a `CallScope`; a missing name, a throw, a non-array, a non-`ScopeGrant` entry, 101 values, and a resolver slower than `scopes.timeout_ms` each give null (unresolved); extra names are ignored; an empty name list never calls the resolver; the exception is held with `Failure::hold` and its message reaches no log line
- [ ] T011 Create `src/Contracts/ResolvesScopes.php`, `src/Scope/NoScopes.php` and `src/Scope/ScopeResolution.php` (contracts/scope-resolver.md; `hrtime` limit from `EffectiveSettings`, research R3)
- [ ] T012 [P] Write test in `tests/Unit/Server/ReasonCodeTest.php`: `scope.unresolved` and `scope.excluded` exist with decision `refused`, and `ResponseBuilder::forReason` answers both with `Request refused.`
- [ ] T013 Add `ScopeUnresolved` and `ScopeExcluded` to `src/Server/ReasonCode.php`
- [ ] T014 [P] Write test `tests/Unit/Server/ReceiptScopeTest.php`: `Receipt::write(..., scope: [...])` writes `scope` with name, mode and removed; omitted when null; a sentinel scope value passed anywhere in the call never appears in the line
- [ ] T015 Add the optional `scope` parameter to `src/Server/Receipt.php`, and pass `CallScope::current()?->summary()` from `ResponseBuilder::receipt` in `src/Server/ResponseBuilder.php`
- [ ] T016 [P] Write test `tests/Unit/Settings/ScopeSettingsTest.php`: `scopes.timeout_ms` is an allowed key; strict gives 500, balanced 2000; 2001 fails `SettingsValidator` naming the key; `config/context-gate.php` has `scopes.timeout_ms` null
- [ ] T017 Add `scopes.timeout_ms` to `config/context-gate.php`, `src/Settings/SettingsSchema.php`, `src/Settings/Profiles.php` and `src/Config/CapsValidator.php`
- [ ] T018 Bind `ResolvesScopes` to `NoScopes` in `src/ContextGateServiceProvider.php` (`bindIf`, so a host binding wins); covered by a case in `tests/Unit/Server/ExtensionPointsTest.php` written first

---

## Phase 3: User Story 1 - A caller sees only the rows of its scope (Priority: P1) 🎯 MVP

**Goal**: a scoped contract declares its scope; rows outside the caller's values never leave; the receipt counts them.

**Independent Test**: seed F and G; caller holding F calls a probe tool returning all entries; only F rows; receipt `scope: [{federation, restricted, <G count>}]`.

### Tests for User Story 1

- [ ] T019 [P] [US1] Write test `tests/Unit/Contract/ScopeDeclarationTest.php`: a contract whose `scope()` returns null fails `ContractValidator` with `contract.scope_undeclared` naming the class; `Scope::none()` passes; a scope column absent from the catalog fails naming table and column; a related contract with no declaration fails
- [ ] T020 [P] [US1] Write test `tests/Unit/Pipeline/ScopeFilterTest.php`: rows of F kept and rows of G removed; a row missing the column, or with null, removed (US1 scenario 3); scope columns not listed by the contract are removed from kept rows; removed counts per name; mode `all` keeps every row; no names in force passes rows unchanged
- [ ] T021 [P] [US1] Write test `tests/Contract/Scope/ScopedRowsTest.php` (with `GateCallHarness` and `FakeScopes`): US1 scenarios 1 and 2; the receipt has `scope` and no federation value; truncation applies after the filter (a G row larger than the per-call limit does not cause truncation of F rows)
- [ ] T022 [P] [US1] Update `tests/Contract/PipelineOrderTest.php`: the recorded stage list starts with `scope`, then `classify`

### Implementation for User Story 1

- [ ] T023 [US1] Add `scope(): ?Scope` (default null) to `src/Contract/ContextContract.php` and the R1 rules to `src/Contract/ContractValidator.php`
- [ ] T024 [US1] Return `Scope::none()` from every package contract: `src/Source/Pseudo/{ConfigKeys,Docs,OpenApiEndpoints,Routes,SchemaItems}Contract.php`, `src/Runbook/RunbookRowsContract.php`; and from every fixture contract in `tests/Fixtures/*Contract.php` and `tests/Fixtures/Quickstart/OrdersContract.php`. Add `tests/Fixtures/ScopedEntryContract.php` (scope `federation` => `federation_id`, relation `athlete_id` => `ScopedAthleteContract`) and `tests/Fixtures/ScopedAthleteContract.php` (scope `federation`)
- [ ] T025 [US1] Create `src/Pipeline/ScopeFilter.php` (research R5)
- [ ] T026 [US1] Create `src/Server/ScopeStep.php` (resolve and bind; pseudonym check comes in US3) and call it from `src/Server/GateCallTool.php` between `ArgumentsStep` and `FetchStep`, unbinding `context-gate.scope` in a `finally`
- [ ] T027 [US1] Run `ScopeFilter` as stage `scope` first in `src/Server/FirewallRunner.php`; record removed counts on the bound `CallScope`
- [ ] T028 [US1] Update the stage list in `specs/002-context-firewall/contracts/firewall-pipeline.md` (stage `scope` before `classify`, citing spec 014 FR-009)

**Checkpoint**: US1 passes alone with a resolver that grants F.

---

## Phase 4: User Story 2 - Fail closed when the scope cannot be resolved (Priority: P1)

**Goal**: no value, no grant, no resolver, error, slow, malformed or over-cap: refused before the handler.

**Independent Test**: caller without scope calls a scoped tool; `Request refused.`; receipt `scope.unresolved`; `ProbeTool::$called` false.

### Tests for User Story 2

- [ ] T029 [P] [US2] Write test `tests/Contract/Scope/ScopeUnresolvedTest.php`: US2 scenarios 1, 2 and 4, plus the default `NoScopes` binding, a malformed return and a resolver over the time limit; each response is byte-identical to a `policy.denied` refusal; the handler is never called; the receipt carries `scope` with mode `unresolved` and no value (SC-002, SC-006)
- [ ] T030 [P] [US2] Write test `tests/Contract/Scope/ScopeCheckCommandTest.php`: `gate:check` exits 1 and names a contract with no scope declaration (US2 scenario 3); exits 0 when every configured contract declares one
- [ ] T031 [P] [US2] Write test `tests/Contract/Scope/ScopeNoCacheTest.php`: the resolver is called once per call; changing its grant between two calls changes the rows of the second call (FR-008, SC-006)

### Implementation for User Story 2

- [ ] T032 [US2] Make `src/Readiness/Checks/ContractCheck.php` report `contract.scope_undeclared` (it already reports `ContractValidator` violations; extend its fix hint to name `scope()`)
- [ ] T033 [US2] In `src/Server/ScopeStep.php` refuse with `ScopeUnresolved` when `ScopeResolution` returns null, writing the receipt with the unresolved summary

**Checkpoint**: US1 and US2 pass; every failure mode of research R2 is refused.

---

## Phase 5: User Story 3 - The model cannot change the scope (Priority: P1)

**Goal**: arguments only narrow; a pseudonym of an out-of-scope record is refused like an unknown one.

**Independent Test**: caller scoped to F sends a G federation id and a pseudonym of a G athlete; no G row; pseudonym case refused with `scope.excluded`.

### Tests for User Story 3

- [ ] T034 [P] [US3] Write test `tests/Contract/Scope/ScopePseudonymTest.php`: US3 scenario 2 response is byte-identical to the `pseudonym.unknown` response; the receipt says `scope.excluded`; a pseudonym issued while the caller held F and G, presented after G was removed, is `scope.excluded` (edge case)
- [ ] T035 [P] [US3] Write test `tests/Contract/Scope/ScopeArgumentFuzzTest.php`: a probe tool that returns rows filtered by any argument; 200 generated argument sets (federation ids of F and G, nulls, arrays, `*`, empty strings) never return a G row to an F caller (US3 scenario 1, SC-004)

### Implementation for User Story 3

- [ ] T036 [US3] Add the pseudonym-argument check to `src/Server/ScopeStep.php` (research R4 step 3): one query per resolved pseudonym argument on the model table, `CallScope::admits` on the scope columns, `ScopeExcluded` with `ResponseBuilder::refused($request)`

**Checkpoint**: all P1 stories pass.

---

## Phase 6: User Story 4 - Relations, query tool and aggregates obey the same scope (Priority: P2)

**Goal**: related records outside the scope are null with `scope.excluded`; specs 012 and 013 have `apply` and `bindingKey` to call.

**Independent Test**: an F entry linked to a G athlete returns the relation as null with `athlete_id` dropped as `scope.excluded`.

### Tests for User Story 4

- [ ] T037 [P] [US4] Write test `tests/Unit/Pipeline/RelationsScopeTest.php`: a related record outside the scope becomes null and adds one `{field: athlete_id, reason: scope.excluded}` per call; a related record that does not exist stays null with no drop entry; an unscoped related contract is not checked (US4 scenarios 1, 2)
- [ ] T038 [P] [US4] Write test `tests/Unit/Scope/CallScopeQueryTest.php`: on sqlite, `apply()` on a base query over `scoped_entries` returns only F rows for an F caller and every row for `all`; ordering and `limit` applied after `apply()` never include a G row (contract for specs 012 and 013, US4 scenarios 3 and 4)

### Implementation for User Story 4

- [ ] T039 [US4] Extend `src/Pipeline/Relations.php` (research R6): read the related scope, drop out-of-scope records before classification, add the `scope.excluded` drop entry, count removals on the `CallScope`; keep the file within 250 lines by moving the record loader into a private helper if needed
- [ ] T040 [US4] Include related scope names in the names in force in `src/Server/ScopeStep.php` (a related contract scoped by a name the caller lacks refuses the call with `scope.unresolved`)

**Checkpoint**: relations obey the scope; specs 012 and 013 may now be enabled on scoped data.

---

## Phase 7: User Story 5 - An explicit all-rows grant for trusted roles (Priority: P3)

**Goal**: `ScopeGrant::all()` per name; receipt mode `all`; still refused for a second name without a grant.

**Independent Test**: caller with `all` for `federation` sees F and G; receipt mode `all`.

- [ ] T041 [P] [US5] Write test `tests/Contract/Scope/ScopeAllGrantTest.php`: US5 scenarios 1 and 2; a contract scoped by `federation` and `event` with `all` for `federation` and nothing for `event` is refused `scope.unresolved`
- [ ] T042 [US5] Fix whatever T041 shows in `src/Scope/CallScope.php` or `src/Server/ScopeStep.php` (expected green after Phase 6; if green when written, mark it characterization)

---

## Phase 8: Rule hash and privacy suite

- [ ] T043 [P] Write test in `tests/Unit/Contract/RuleHashTest.php`: changing a contract's scope column, adding a name, or switching to `none` changes the `contract` rule hash (FR-018)
- [ ] T044 Add `scope` canonical to each contract and related contract entry in `ContextGateServiceProvider::ruleHashes` in `src/ContextGateServiceProvider.php`
- [ ] T045 [P] Write test `tests/Contract/Scope/ScopePrivacyTest.php`: seed `FAKE_FIXTURE_ONLY_SCOPE_` sentinel values as federation ids; run every scenario of US1–US5; no response, receipt line or failure note contains a sentinel or its sha256 (SC-001, SC-003)

---

## Phase 9: Polish & Cross-Cutting Concerns

- [ ] T046 [P] Document `ResolvesScopes`, `ScopeGrant`, `Scope::by`/`none`, `CallScope::current()`, the time-limit caveat (research R3) and the `NoScopes` default in `docs/extending.md`
- [ ] T047 [P] Add `scope.unresolved` and `scope.excluded` to the reason-code table and the `scope` receipt field to the receipt section of `docs/privacy.md`
- [ ] T048 [P] Add `scopes.timeout_ms` to `docs/configuration.md`
- [ ] T049 [P] Add an Unreleased entry to `CHANGELOG.md`: row scopes; every contract must now declare `scope()` (breaking); new stage `scope`
- [ ] T050 [P] Add a short "Row scopes" section to `README.md` linking `docs/extending.md`
- [ ] T051 Run quickstart.md section 3 against the fixture app in a test (`tests/Contract/Scope/ScopeQuickstartTest.php`) so the documented table stays true
- [ ] T052 Run the four gates and read the exit codes: `composer test`, `composer stan`, `composer style`, `composer length`; all must exit 0

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 → US1 (Phase 3) → US2 → US3 → US4 → US5 → Phase 8 → Polish.
- US2 needs `ScopeStep` from US1 (T026). US3 needs US2's refusal path. US4 needs `ScopeFilter` and `CallScope` counts.
- T024 touches every contract fixture: run it before any other test is added in the same PR, or the existing suite fails
  on `contract.scope_undeclared`.
- **Cross-spec**: spec 010 before this file (research R11). Spec 012 Phase "scope binding" and spec 013 scope tasks need
  T038/T039 (Phase 6) done.

### Parallel Opportunities

- T001, T003; all Phase 2 test tasks (T004, T006, T008, T010, T012, T014, T016) are on separate files.
- US1 tests T019–T022 in parallel; US4 tests T037, T038 in parallel; Polish T046–T050 in parallel.

## Implementation Strategy

MVP is US1 + US2 + US3 (all P1): a scoped contract cannot leak rows and fails closed. US4 is required before spec 012 or
013 is enabled on scoped data. US5 is small and can ship with US4.
