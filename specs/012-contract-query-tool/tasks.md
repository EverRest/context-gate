# Tasks: Contract Query Tool with Pagination

**Input**: Design documents from `specs/012-contract-query-tool/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R13), data-model.md, contracts/. Spec 014 Phases 1–6 merged
(`Scope`, `CallScope::apply`, `CallScope::bindingKey`, `ScopeStep`), which in turn follows spec 010 (research R13).
Until then no query tool may be enabled on a contract whose rows need a row scope.

**Tests**: REQUIRED (constitution IX). Every implementation task is preceded by a test task that must fail first. No
test touches the network. Filter sentinels use the prefix `FAKE_FIXTURE_ONLY_FILTER_`.

**Organization**: by user story. US1, US2, US3 are P1. US4 and US5 are P2.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1…US5 from spec.md
- Paths are relative to the repository root

---

## Phase 1: Setup

- [ ] T001 [P] Write test in `tests/Unit/Pipeline/CapsTest.php`: `Caps::QUERY_PAGE_SIZE` 200, `Caps::CURSOR_TTL` 3600, `Caps::FILTER_IN_VALUES` 20, `Caps::CURSORS_PER_CALLER` 100
- [ ] T002 Add the four constants to `src/Pipeline/Caps.php`
- [ ] T003 [P] Write test `tests/Unit/Settings/QuerySettingsTest.php`: `query.page_size` and `query.cursor_ttl_seconds` are allowed keys; strict 50 and 900, balanced 200 and 3600; 201 and 3601 fail `SettingsValidator` naming the key; both default to null in `config/context-gate.php`
- [ ] T004 Add both keys to `config/context-gate.php`, `src/Settings/SettingsSchema.php`, `src/Settings/Profiles.php`, `src/Config/CapsValidator.php`
- [ ] T005 [P] Create fixtures `tests/Fixtures/QueryEntryRecord.php` (table `query_entries`: id, federation_id, status, event_code, internal_note_code (internal), athlete_id, age (personal), entered_at, comment (text)), `tests/Fixtures/QueryEntryContract.php` (plain, pseudonym `ATH`, bucket [18, 25, 35], date month, text; `Scope::none()`), `tests/Fixtures/QueryScopedEntryContract.php` (same, scoped by `federation`), and `tests/Fixtures/QueryTables.php` (creates and seeds 250 rows with `SeededPii` values and `CanaryValues` in one known row)

---

## Phase 2: Foundational

**Purpose**: the reusable filter rules (spec 013 depends on them), the declaration, reason codes and receipt field.

**Checkpoint**: `FilterRules`, `FilterCompiler`, `QueryDeclaration`, `ReasonCode`, `Receipt` unit tests are green.

- [ ] T006 [P] Write test `tests/Unit/Query/FilterRulesTest.php`: the full research R4 table: for each form × class, `operatorsFor()` returns exactly the allowed operators (none for text, aggregate, relation); `schemaFor()` emits the fragments of `contracts/query-tool-input.schema.json` (pseudonym pattern with the field's prefix, bucket enum of `Transformer` labels, date pattern per precision, `maxItems` 20); `ceilingDenies()` is true for a plain `internal` field and a `public` ceiling, false for pseudonym, bucket and date forms
- [ ] T007 Create `src/Query/FilterRules.php` and `src/Query/Filter.php` (class doc states it is shared with spec 013)
- [ ] T008 [P] Write test `tests/Unit/Query/FilterCompilerTest.php` on sqlite: `eq`, `in`, range (from only, to only, both, inclusive), bucket labels to half-open ranges ORed inside one group, date year and month to `[start, next)`; the generated SQL has only `?` placeholders for values (assert `toSql()` contains no seeded value) and columns only from the `Filter` objects
- [ ] T009 Create `src/Query/FilterCompiler.php`
- [ ] T010 [P] Write test `tests/Unit/Query/QueryDeclarationTest.php`: a valid declaration parses; every FR-004 violation of research R2 is reported naming tool and field (unlisted field, text/aggregate/relation filter, bad operator for the form, sort on a pseudonym/bucket/date/personal field, page size 0 or 201, unknown key, empty filters without `unfiltered`); `canonical()` is key-sorted and changes when `unfiltered` flips
- [ ] T011 Create `src/Query/QueryDeclaration.php` (memoised per entry and classification map, like `ContractGuard`)
- [ ] T012 [P] Write test in `tests/Unit/Server/ReasonCodeTest.php`: `cursor.invalid` has decision `invalid` and `ResponseBuilder::forReason` answers it with `Invalid arguments for tool [<name>].`, byte-identical to `input.invalid`; `filter.denied` has decision `refused` and answers `Request refused.`
- [ ] T013 Add `CursorInvalid` and `FilterDenied` to `src/Server/ReasonCode.php` and map `CursorInvalid` next to `InputInvalid` in `ResponseBuilder::forReason` in `src/Server/ResponseBuilder.php`
- [ ] T014 [P] Write test `tests/Unit/Server/ReceiptQueryTest.php`: `Receipt::write(..., query: [...])` writes `query` with filters (field, op), sort, unfiltered, cursor_in, cursor_out, page, walk; omitted when null
- [ ] T015 Add the optional `query` parameter to `src/Server/Receipt.php`; `ResponseBuilder::receipt` passes the bound `QueryPage`/`QueryPlan` summary

---

## Phase 3: User Story 1 - Declare a query tool from a contract (Priority: P1) 🎯 MVP

**Goal**: a config entry with `class => QueryTool::class` and `query` becomes a listed, pinned, gated tool.

**Independent Test**: declare `entries.list` on `QueryEntryContract` with one public filter and one sort; pin; allow; call with a filter; rows match and pass the firewall.

### Tests for User Story 1

- [ ] T016 [P] [US1] Write test `tests/Unit/Query/QueryToolSchemaTest.php`: `new QueryTool('entries.list')` has the entry key as name, a description naming only declared fields and operators (no data), the `Tool` default annotations, and an input schema equal to the generated one; `required: ["filters"]` present iff `unfiltered` is false; `Fingerprint::of` changes when a filter field, an operator, the page size or `unfiltered` changes (US1 scenario 4)
- [ ] T017 [P] [US1] Write test `tests/Contract/Query/QueryToolListTest.php`: an allowed caller sees the tool with exactly the declared filter and sort arguments (US1 scenario 1); unpinned or mismatched pin hides it like a hand-written tool, receipt `tool.pin_mismatch` (scenario 3)
- [ ] T018 [P] [US1] Write test `tests/Unit/Readiness/Checks/QueryCheckTest.php`: `gate:check` fails and names tool and field for a declaration with an unlisted field (US1 scenario 2) and for each R2 violation; prints one line per tool with `unfiltered: true` (FR-026)
- [ ] T019 [P] [US1] Write test `tests/Contract/Query/QueryFirstPageTest.php`: a call with `status eq` returns only matching rows, projected and transformed by the contract (pseudonym, bucket, date forms; text redacted); `PipelineOrderTest`-style marks show every stage ran in order (FR-005); a declaration violation per call is `config.invalid`

### Implementation for User Story 1

- [ ] T020 [US1] Create `src/Query/QueryTool.php` (reads its entry from `context-gate.tools.<key>`; `schema()` from `FilterRules::schemaFor`; `handle()` delegates to `QueryRunner` with the bound `QueryPlan`)
- [ ] T021 [US1] In `src/Server/ToolRegistry.php` `instantiate()`, build `new QueryTool($key)` for a `QueryTool` class (as for `RunbookTool`)
- [ ] T022 [US1] Create `src/Query/QueryPlan.php`, `src/Query/QueryPage.php` and `src/Query/QueryRunner.php` (builder on the model connection, soft deletes, `CallScope::apply`, filters, sort + key tie-breaker, `limit(page_size + 1)`; research R5, R6)
- [ ] T023 [US1] Create `src/Server/QueryStep.php` (declaration check → `config.invalid`; bind `QueryPlan`; unbind in `GateCallTool`'s `finally`) and run it in `src/Server/GateCallTool.php` after `ScopeStep` for a `QueryTool` only
- [ ] T024 [US1] Create `src/Readiness/Checks/QueryCheck.php` and register it in `src/Readiness/CheckRegistry.php`
- [ ] T025 [US1] Add the canonical `query` declaration to the tool rule hash in `ContextGateServiceProvider::ruleHashes` (`src/ContextGateServiceProvider.php`), with a case in `tests/Unit/Contract/RuleHashTest.php` written first

**Checkpoint**: a query tool can be declared, listed, pinned and called for its first page.

---

## Phase 4: User Story 2 - Filter only in forms that cannot reveal a raw value (Priority: P1)

**Goal**: FR-006 to FR-011 and FR-026 at call time.

**Independent Test**: the full form × class × operator matrix over seeded personal values and canaries; only allowed combinations return rows; no seeded value appears anywhere.

### Tests for User Story 2

- [ ] T026 [P] [US2] Write test `tests/Unit/Query/QueryArgumentsTest.php`: mixing `eq` and `in` on one field, an unknown field, a bucket label that is not declared, a date finer than the precision, 21 `in` values, a sort field not declared are `input.invalid`; a plain internal filter or sort with a public ceiling is `filter.denied`; no filters on a non-opted-in tool is `input.invalid`; filters that are all malformed are refused as malformed, not as unfiltered (edge case)
- [ ] T027 [P] [US2] Write test `tests/Contract/Query/QueryFilterMatrixTest.php`: US2 scenarios 1–8 through the gate; a generated matrix of every form × class × operator × ceiling asserts allowed ⇒ rows, otherwise refused with the expected reason (SC-004); a filter equal to a canary value is refused `canary.hit` before any query runs (FR-011)
- [ ] T028 [P] [US2] Write test `tests/Contract/Query/QueryPseudonymFilterTest.php`: an issued pseudonym returns that record's rows; unknown, expired, another caller's, another table's, and (for a `day` field) a rotated pseudonym are refused byte-identically to an unknown pseudonym argument, receipt `pseudonym.unknown`

### Implementation for User Story 2

- [ ] T029 [US2] Create `src/Query/QueryArguments.php` (parse, one-operator rule, unfiltered rule, ceiling via `FilterRules::ceilingDenies`)
- [ ] T030 [US2] Expose `PseudonymArguments::record()` as `public static resolveOne()` in `src/Server/PseudonymArguments.php` and use it for pseudonym filters in `QueryArguments`
- [ ] T031 [US2] Wire `QueryArguments` into `src/Server/QueryStep.php`: `input.invalid` (text `Invalid arguments for tool [<name>].`), `filter.denied`, `pseudonym.unknown` receipts carry the `query` field

**Checkpoint**: all filter rules hold; P1 security core done.

---

## Phase 5: User Story 3 - Fetch the next page after truncation (Priority: P1)

**Goal**: cursor issue and follow, keyset continuation after page size or byte truncation, last page without cursor.

**Independent Test**: 250 rows, page size 100: the union of pages equals the filtered set, in order, no duplicates; the last page has no cursor.

### Tests for User Story 3

- [ ] T032 [P] [US3] Write test `tests/Unit/Query/CursorsTest.php` (array store, fake clock): issue returns `^c_[0-9a-f]{32}$`; state holds binding, position, page, walk, expires; read with the same binding returns the state; a different binding, an expired state, a missing key, an unreadable store each return null; consume deletes; the 101st issue retires the oldest and deletes its state; both keys are written with the effective TTL
- [ ] T033 [P] [US3] Write test `tests/Unit/Query/QueryRunnerKeysetTest.php` on sqlite: ascending and descending sort with duplicate sort values and nulls; following positions returns every row once in order; a row inserted before the position is not shown and a deleted row is skipped (edge case); the tie-breaker never appears in returned rows unless the contract lists the key
- [ ] T034 [P] [US3] Write test `tests/Contract/Query/QueryWalkTest.php`: US3 scenarios 1–4; a page truncated by a lowered per-call byte limit continues at the first unserved row; an empty result has no cursor; a filter-less call on an opted-in tool walks all 250 rows (SC-002, SC-007); a requested page size above the effective one is clamped

### Implementation for User Story 3

- [ ] T035 [US3] Create `src/Query/Cursors.php` (research R7; lock the index when the store is a `LockProvider`)
- [ ] T036 [US3] Add keyset continuation from `QueryPlan::cursorIn` to `src/Query/QueryRunner.php`; record raw positions and the probe flag on `QueryPage`
- [ ] T037 [US3] Validate a presented cursor in `src/Server/QueryStep.php` (binding over caller, tool, rule hashes, ceiling, `CallScope::bindingKey()`, filters, sort, page size) → `cursor.invalid`
- [ ] T038 [US3] In `src/Server/FirewallStep.php`, for a `QueryTool` call after a served payload: compute `served`, issue the next cursor (or the incomplete outcome, research R8), write the receipt, then consume the presented cursor; keep the file within 250 lines by moving this into `src/Query/QueryOutcome.php` if needed
- [ ] T039 [US3] Extend `ResponseBuilder::result` in `src/Server/ResponseBuilder.php`: `cursor` and `more` in structured content, the "more rows" and "incomplete" notices, no truncation notice for a query page (research R9)

**Checkpoint**: all P1 stories pass; a walk is complete and exact.

---

## Phase 6: User Story 4 - Cursors cannot be replayed, shared or decoded (Priority: P2)

**Goal**: one identical response for every invalid cursor; nothing decodable.

**Independent Test**: caller B, expired, contract changed, one character changed: byte-identical responses, receipts `cursor.invalid`.

- [ ] T040 [P] [US4] Write test `tests/Contract/Query/CursorInvalidTest.php`: US4 scenarios 1–3 plus a ceiling change, a scope change (spec 014 grant changed between pages), a page size change, a store that throws on read; all responses byte-identical (SC-005); receipts `cursor.invalid`
- [ ] T041 [P] [US4] Write test `tests/Contract/Query/CursorOpaqueTest.php`: across a walk, no cursor contains any field value, key, sort value or page number in any encoding (hex, base64, decimal) (US4 scenario 4)
- [ ] T042 [P] [US4] Write test `tests/Concurrency/CursorIndexProcessTest.php` (spec 008 harness): parallel issues by one caller never leave more than 100 live cursors
- [ ] T043 [US4] Fix any failure from T040–T042 in `src/Query/Cursors.php` or `src/Server/QueryStep.php` (expected green after Phase 5; if green when written, mark characterization)

---

## Phase 7: User Story 5 - Pages count against budgets and are fully receipted (Priority: P2)

**Goal**: no free continuation; receipts with names and operators only.

**Independent Test**: lower the session limit, follow cursors until refused `budget.session`; no receipt holds a sentinel.

- [ ] T044 [P] [US5] Write test `tests/Contract/Query/QueryBudgetTest.php`: US5 scenario 1 (the cursor still works after the budget window, before expiry); each page counts one per-minute hit; a walk never exceeds the session and day byte limits (SC-006)
- [ ] T045 [P] [US5] Write test `tests/Contract/Query/QueryCanaryTest.php`: a page containing the seeded canary is blocked `canary.hit`, carries no cursor, and the presented cursor is not consumed (US5 scenario 3, FR-024)
- [ ] T046 [P] [US5] Write test `tests/Contract/Query/QueryReceiptTest.php`: US5 scenario 2; every receipt of a walk has `query` with filters, sort, unfiltered, cursor_in, cursor_out, page and the same `walk`; no filter sentinel, cursor id or sort value appears (FR-023)
- [ ] T047 [US5] Fix any failure from T044–T046 (expected green after Phase 5; if green when written, mark characterization)

---

## Phase 8: Row scope integration (spec 014)

- [ ] T048 [P] Write test `tests/Contract/Query/QueryScopeTest.php` with `QueryScopedEntryContract`: a caller scoped to F walks only F rows on every page; a filter on G returns nothing; a cursor issued under F and presented after the grant changed is `cursor.invalid`; `scope.unresolved` refuses before the query runs (spec 014 US4 scenario 3)
- [ ] T049 Fix any failure from T048 in `src/Query/QueryRunner.php` or `src/Server/QueryStep.php`

---

## Phase 9: Privacy suite

- [ ] T050 Write test `tests/Contract/Query/QueryPrivacyTest.php`: seed `SeededPii`, `CanaryValues` and `FAKE_FIXTURE_ONLY_FILTER_` sentinels; run every story's scenarios; assert no seeded personal value, canary or sentinel appears in any response, cursor, receipt line or failure note (SC-003)

---

## Phase 10: Polish & Cross-Cutting Concerns

- [ ] T051 [P] Add `docs/query-tools.md`: declaration, operators table (research R4), paging and cursors, the bulk-export consequence of `unfiltered` (spec assumption), and "enable only after row scopes on scoped data"; link it from `docs/configuration.md`
- [ ] T052 [P] Add `cursor.invalid` and `filter.denied` to the reason-code table and the `query` receipt field to `docs/privacy.md`
- [ ] T053 [P] Add `query.page_size` and `query.cursor_ttl_seconds` to `docs/configuration.md`
- [ ] T054 [P] Add a note to `docs/extending.md` that `FilterRules` and `FilterCompiler` are the shared filter unit for spec 013 (internal API)
- [ ] T055 [P] Add an Unreleased entry to `CHANGELOG.md`: generated query tools with cursor pagination; new reason codes; new settings
- [ ] T056 [P] Add a "Query tools" section to `README.md` with the quickstart declaration
- [ ] T057 Turn quickstart.md section 3 into `tests/Contract/Query/QueryQuickstartTest.php` so the documented refusals stay true
- [ ] T058 Run the four gates and read the exit codes: `composer test`, `composer stan`, `composer style`, `composer length`; all must exit 0

---

## Dependencies & Execution Order

- **External**: spec 014 Phases 1–6 before Phase 3 here (`CallScope`, `ScopeStep` exist); spec 010 before spec 014.
- Phase 1 → Phase 2 → US1 → US2 → US3 → US4, US5 (parallel) → Phase 8 → Phase 9 → Polish.
- T029–T031 need `QueryStep` (T023). T037–T039 need `Cursors` (T035). Phase 8 needs Phase 5.
- **Spec 013** may start after Phase 2 (it needs `FilterRules`, `FilterCompiler`, `Filter`, `filter.denied`), and needs
  `QueryArguments` (T029) for its filter parsing.

### Parallel Opportunities

- T001, T003, T005; Phase 2 test tasks T006, T008, T010, T012, T014.
- US1 tests T016–T019; US2 tests T026–T028; US3 tests T032–T034; US4 tests T040–T042; US5 tests T044–T046.
- Polish T051–T056.

## Implementation Strategy

MVP is US1 + US2 + US3 (all P1): a declared tool, safe filters and complete walks. US4 and US5 harden cursors and
budgets and are required before release. Phase 8 is required before enabling a query tool on scoped data.
