# Tasks: k-Anonymous Aggregates

**Input**: Design documents from `specs/013-k-anonymous-aggregates/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R10), data-model.md, contracts/aggregate-tool.md. Spec 012 must be
implemented before the tasks marked **[012]** (filter unit, declared-tool factory, `filter.denied`, receipt filter
shape); spec 014 before those marked **[014]** (scope resolution, `scope.unresolved`). Class names taken from 012 and
014 are provisional (research header) and are renamed here when those plans land.

**Tests**: REQUIRED (constitution IX). Every implementation task is preceded by a test task that must fail first.
*Characterization* tasks lock behaviour that already exists; their test passes when written. No test uses the network.

**Organization**: by user story. US1 and US2 are P1; US3, US4 and US5 are P2.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1…US5 from spec.md
- Paths are relative to the repository root

---

## Phase 1: Setup

- [ ] T001 Write test `tests/Unit/Pipeline/CapsAggregateTest.php`: `Caps::AGG_K_FLOOR` = 5, `AGG_K_CAP` = 1000, `AGG_GROUP_BY` = 2, `AGG_GROUPS` = 100, `AGG_PER_DAY` = 500, `AGG_SCAN_CELLS` = 10000
- [ ] T002 Edit `src/Pipeline/Caps.php` to add the six `AGG_*` constants
- [ ] T003 [P] Write test `tests/Unit/Server/ReasonCodeAggregateTest.php`: `aggregate.budget` has decision `refused`; `aggregate.suppressed` has decision `dropped`
- [ ] T004 Edit `src/Server/ReasonCode.php` to add `AggregateBudget` and `AggregateSuppressed` with their decisions
- [ ] T005 [P] Write test in `tests/Unit/Settings/ConfigDefaultsTest.php`: `config/context-gate.php` has `aggregates` with `k`, `max_group_by`, `max_groups`, `queries_per_day` all null
- [ ] T006 Edit `config/context-gate.php` to add the `aggregates` block with a comment per key (data-model Setting keys)

---

## Phase 2: Foundational

**Purpose**: settings, the pure suppression rule and the declaration parser. No database, no 012/014 needed.

**Checkpoint**: `Suppression` passes the SC-001 matrix; settings validation reports every aggregate key violation.

- [ ] T007 [P] Write test `tests/Unit/Settings/SettingsValidatorAggregatesTest.php`: `aggregates.k` = 4 and = 1001 are violations naming `aggregates.k`; 5 and 1000 pass; `max_group_by` 3, `max_groups` 101, `queries_per_day` 501 and any value 0 are violations naming the key; null passes; `aggregates.k` is not a key of `Profiles::for()` for either profile
- [ ] T008 Edit `src/Settings/SettingsSchema.php` (allow `aggregates.*` int-or-null and `tools.*.aggregate` array) and `src/Settings/SettingsValidator.php` (floor rule for `aggregates.k`, caps for the other three, all ≥ 1)
- [ ] T009 [P] Write test `tests/Unit/Aggregate/AggregateSettingsTest.php`: `k()` is 5 with nothing set; `aggregates.k` = 8 gives 8; `k(declared: 10)` with config 8 gives 10 and `k(declared: 6)` with config 8 gives 8 (max); `maxGroupBy()`, `maxGroups()`, `queriesPerDay()` default to 2, 100, 100 and never exceed their caps
- [ ] T010 Create `src/Aggregate/AggregateSettings.php`
- [ ] T011 [P] Write test `tests/Unit/Aggregate/SuppressionTest.php` (contracts/aggregate-tool.md table): 12, 7, 3, 3 with k = 5 gives groups 12 and 7, other 6, total 25, not withheld; 12, 7, 2 gives no other, no total, withheld; all cells below k gives no named group and the other/total rule; one suppressed cell is never returned as other; no group-by with 3 is withheld and with 0 returns total 0; 3 named groups with `maxGroups` 2 moves the smallest into other and sets `cut`; percentages are one decimal over the returned total and absent when withheld; the notice list contains no label and no number
- [ ] T012 [P] Write test `tests/Unit/Aggregate/SuppressionDeterminismTest.php`: shuffling the input cell order gives an identical `AggregateResult`; ties on count are ordered by the canonical JSON of the label tuple; a `null` label sorts deterministically (FR-013, SC-003)
- [ ] T013 [P] Write test `tests/Unit/Aggregate/SuppressionMatrixTest.php` (SC-001): over every multiset of up to 6 cells with counts 1..12 and k in {5, 7}, no returned count lies in 1..k-1, `other` is absent or ≥ k, `total` is absent or equals the sum of returned counts, and when exactly one cell is suppressed no returned number or difference of `total` and the named sum equals its count
- [ ] T014 Create `src/Aggregate/AggregateResult.php` and `src/Aggregate/Suppression.php` (research R3)
- [ ] T015 [P] Write test `tests/Unit/Aggregate/AggregateDeclarationTest.php` (research R1): on a fixture contract with plain public `nation`, plain internal `status`, bucket `age`, personal `birth_date` not in the contract, pseudonym `athlete`, text `note`, relation `event` and a secret column — `value` on `nation` and `status`, `bucket` on `age`, `year`/`month` on `birth_date` are valid; `value` on `birth_date`, any kind on `athlete`, `note`, `event`, the secret column or an unclassified column are violations naming tool and column; `month` on a column the contract lists as `Field::date('year')` is a violation; `k` 4 or 1001 is a violation; an entry with both `class` and `aggregate` is a violation; an empty `group_by` is a violation
- [ ] T016 Create `src/Aggregate/AggregateDeclaration.php` with `fromEntry(string $tool, array $entry)` and `violations(Catalog $catalog): list<string>`; add the fixtures `tests/Fixtures/AggregateEntryContract.php` and `tests/Fixtures/AggregateEntryModel.php`

---

## Phase 3: User Story 1 - Count rows grouped by an allowed field (Priority: P1)

**Goal**: a declared tool returns counts per allowed key, groups below k not named.

**Independent Test**: groups 12, 7, 2 with k = 5: the response names 12 and 7 only.

### Tests for User Story 1

- [ ] T017 [P] [US1] Write test `tests/Unit/Aggregate/AggregateToolTest.php`: the generated tool's name is the entry key; its description states k and the keys; its input schema has `group_by` (enum of declared keys, `maxItems` = effective max_group_by, `uniqueItems`), `percent` and the 012 `filters` schema; annotations are read-only; `Fingerprint::of` changes when k, a key or a filter changes
- [ ] T018 [US1] [012] Create `src/Aggregate/AggregateTool.php`, and edit `src/Server/ToolRegistry.php` to build it from an entry with an `aggregate` key through the 012 declared-tool factory
- [ ] T019 [P] [US1] [012] Write test `tests/Unit/Aggregate/AggregateQueryTest.php` (SQLite in memory): `cells()` groups by one and two keys with combined cells; nulls form their own cell; bucket and year/month labels equal `Transformer` labels for the same values; filters from the 012 filter unit narrow the rows; more than `AGG_SCAN_CELLS` folded cells throws
- [ ] T020 [US1] [012] Create `src/Aggregate/AggregateQuery.php` (model `newQuery()`, 012 `FilterSet` where clauses, `GROUP BY` raw columns, `COUNT(*)`, `cursor()` fold, research R4)
- [ ] T021 [P] [US1] Write test `tests/Contract/Aggregate/GroupByTest.php` through `tests/Contract/GateCallHarness.php`: seeded 12, 7, 2 with k = 5 returns the contract JSON with 12 and 7 and a notice; a `group_by` key not declared is `Invalid arguments: …` with receipt `input.invalid`; a declaration with a pseudonym key makes `gate:check` exit 1 naming tool and field (US1 scenarios 1–3)
- [ ] T022 [US1] Create `src/Aggregate/AggregateStep.php` (plan call path steps 1–5) and edit `src/Server/GateCallTool.php` to dispatch an `AggregateTool` to it after `ArgumentsStep`; edit `src/Server/ResponseBuilder.php` to render the aggregate JSON, the notices and `structuredContent`
- [ ] T023 [US1] Edit `src/Readiness/Checks/ContractCheck.php` to report `AggregateDeclaration::violations()` per tool, and `src/Settings/SettingsValidator.php` so an invalid declaration makes the settings invalid (`config.invalid` per call)

**Checkpoint**: US1 works for public/internal `value` keys on an unscoped contract.

---

## Phase 4: User Story 2 - Small groups are suppressed so totals cannot reveal them (Priority: P1)

**Goal**: the other/total rule end to end, and the same floor for `Field::aggregate()`.

**Independent Test**: 12, 7, 3, 3 → other 6, total 25; 12, 7, 2 → no other, no total, notice.

### Tests for User Story 2

- [ ] T024 [P] [US2] Write test `tests/Contract/Aggregate/OtherAndTotalTest.php`: US2 scenarios 1–4 through the harness, including `percent: true` withheld with the total and no-group-by counts 3 (withheld) and 0 (returned)
- [ ] T025 [P] [US2] Write test in `tests/Unit/Pipeline/TransformerTest.php`: a `Field::aggregate()` count of 3 with k = 5 is null and adds one dropped entry `aggregate.suppressed` for the column; counts 0 and 5 are unchanged; update the existing assertions that expected small counts
- [ ] T026 [US2] Edit `src/Pipeline/Transformer.php` (constructor `int $k = Caps::AGG_K_FLOOR`, null + dropped entry) and `src/Server/FirewallRunner.php` (pass `AggregateSettings::k()`)
- [ ] T027 [P] [US2] Write test `tests/Contract/Aggregate/AggregateFieldReceiptTest.php`: a hand-written tool whose contract has `Field::aggregate()` over 3 rows returns null for that field and its receipt lists the field with `aggregate.suppressed` (FR-012)

---

## Phase 5: User Story 3 - Group personal data only through coarse forms (Priority: P2)

**Goal**: bucket and year/month keys on personal columns; row contracts unchanged.

**Independent Test**: group by a personal birth date's year works; `Field::date` on a personal column in a row contract still fails `gate:check`.

### Tests for User Story 3

- [ ] T028 [P] [US3] Write test `tests/Contract/Aggregate/PersonalKeysTest.php`: a personal bucket key returns groups labelled by bucket bounds with suppression; a personal `birth_date` `year` key returns year labels with groups below k suppressed; `month` works; no response contains a seeded birth date or age from `tests/Fixtures/SeededPii.php` (US3 scenarios 1 and 3)
- [ ] T029 [P] [US3] Write test `tests/Unit/Contract/PersonalDateRowContractTest.php` (*characterization*, SC-006): a row contract with `Field::date('year')` on a personal column fails `ContractValidator` and `gate:check`; the aggregate declaration on the same column passes
- [ ] T030 [US3] Edit `src/Aggregate/AggregateQuery.php` and `src/Aggregate/AggregateStep.php` for `bucket`, `year` and `month` keys, ceiling skipped for non-raw kinds (research R1); expected green after T020/T022 unless a gap shows

---

## Phase 6: User Story 4 - Repeated queries cannot be differenced cheaply (Priority: P2)

**Goal**: determinism, the daily aggregate budget, receipts with names and operators, documented residual risk.

**Independent Test**: two identical calls give byte-identical responses; call N+1 is refused with `aggregate.budget`.

### Tests for User Story 4

- [ ] T031 [P] [US4] Write test `tests/Unit/Aggregate/AggregateBudgetTest.php` (array store, fixed clock): the limit-th call is admitted and the next refused; the key is `aggregate:day:{caller}:{Y-m-d}` with TTL equal to `Budget::secondsUntilUtcMidnight`; a new UTC day resets it; two callers are independent; a store that throws makes `admit()` throw (fail closed)
- [ ] T032 [US4] Create `src/Aggregate/AggregateBudget.php` (research R7) and edit `src/Server/Admission.php` to call it for aggregate tools after the per-minute admit
- [ ] T033 [P] [US4] Write test `tests/Contract/Aggregate/DeterminismTest.php` (SC-003): the same call twice over unchanged rows gives byte-identical `tools/call` results; inserting rows in a different physical order gives the same bytes
- [ ] T034 [P] [US4] Write test `tests/Contract/Aggregate/BudgetRefusalTest.php`: with `queries_per_day` = 2 the third call answers `Request refused.` and its receipt records `aggregate.budget`; a call with invalid arguments also counts; aggregate calls also count toward the per-minute limit and their bytes toward the session budget (FR-015)
- [ ] T035 [P] [US4] Write test `tests/Contract/Aggregate/AggregateReceiptTest.php` (FR-020): the receipt has `aggregate.group_by`, `aggregate.filters` (field and op), `k`, `groups`, `suppressed`, `withheld`; a sentinel filter value, every group label and every count are absent from the receipt line
- [ ] T036 [US4] Edit `src/Server/Receipt.php` (optional `?array $aggregate`) and `src/Server/ResponseBuilder.php` (`receipt(..., aggregate:)`); `src/Aggregate/AggregateStep.php` passes it

---

## Phase 7: User Story 5 - Aggregates respect ceilings and row scopes (Priority: P2)

**Goal**: counts cover only the caller's scope; ceiling refusals.

**Independent Test**: two scopes over one fixture; adding out-of-scope rows changes no response.

### Tests for User Story 5

- [ ] T037 [P] [US5] [014] Write test `tests/Contract/Aggregate/ScopedCountTest.php`: caller scoped to F counts only F rows; adding G rows leaves F's response byte-identical (SC-005); an F group of 3 with k = 5 is suppressed; a caller with no scope value is refused with `scope.unresolved` before the query runs
- [ ] T038 [US5] [014] Edit `src/Aggregate/AggregateQuery.php` to apply the 014 resolved scope as `whereIn` per restricted scope name before grouping
- [ ] T039 [P] [US5] [012] Write test `tests/Contract/Aggregate/CeilingTest.php`: a `value` key on an internal column with caller ceiling public is refused and the receipt records `filter.denied`; a filter on that column is refused the same way; a `bucket` or `year` key on a personal column is allowed under ceiling public (non-raw)
- [ ] T040 [US5] [012] Edit `src/Aggregate/AggregateStep.php` to check `value` keys against `Caller::ceiling()` with the effective catalog and refuse with `filter.denied`

---

## Phase 8: Polish and Cross-Cutting

- [ ] T041 [P] Write test in `tests/Contract/PrivacySuiteTest.php`: aggregate calls over the seeded personal fixture and canary rows return no seeded value and no canary; a canary stored in a `value` column blocks the response with `canary.hit` (FR-021, SC-001)
- [ ] T042 Update `docs/privacy.md`: section "Aggregates and differencing" (FR-016: what k protects, the total rule, determinism, 012 filter limits, the daily limit, receipts; counts are exact and not rounded; the residual risk of two overlapping queries and of a group sharing one value; the `Field::aggregate()` work-bound note from research R8); add `aggregate.budget` and `aggregate.suppressed` to the reason-code table
- [ ] T043 [P] Update `docs/configuration.md` (the `aggregates.*` keys, floors and caps) and `docs/extending.md` (declaring an aggregate tool, group-by kinds, quickstart steps)
- [ ] T044 [P] Update `README.md` feature list and `CHANGELOG.md` under Unreleased (new aggregate tool; breaking: `Field::aggregate()` counts 1..k-1 are null)
- [ ] T045 Confirm every new file in `src/Aggregate/` and the edited `src/Server/GateCallTool.php`, `src/Server/ResponseBuilder.php` stay within `scripts/check-file-length.php`
- [ ] T046 Run `composer style`, `composer stan`, `composer length` and `composer test`; read each exit code; all must be 0

---

## Dependencies and Execution Order

- Phase 1 before everything. Phase 2 needs only Phase 1 and can run before specs 012 and 014 land.
- US1 (Phase 3) needs Phase 2 and spec 012 (T018–T020). US2 needs US1 for its contract tests; T025–T026 (the
  `Field::aggregate()` floor) need only Phase 2 and may land earlier.
- US3 and US4 need US1. US5 needs US1, spec 014 (T037–T038) and spec 012 (T039–T040).
- Within a story, each test task runs and fails before its implementation task starts.
- **[P]** tasks touch different files. T025 and the existing `TransformerTest` assertions are one file: do them in one task.

**Task count**: 46 (Setup 6, Foundational 10, US1 7, US2 4, US3 3, US4 6, US5 4, Polish 6).
