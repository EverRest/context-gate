# Tasks: Classification Declared on the Model

**Input**: Design documents from `specs/015-classification-attributes/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R8), data-model.md, contracts/classify-attribute-and-scan-ci.md.
Specs 001–007 complete. Spec 009 is not required (research R7).

**Tests**: REQUIRED (constitution IX). Each implementation task follows a test task that must fail first. Tasks marked
*verification* lock behaviour that already exists at HEAD; their test is expected to pass when written.

**Organization**: by user story. US1, US2, US3 are P1; US4, US5 are P2.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1…US5 from spec.md
- Paths are relative to the repository root

---

## Phase 1: Setup

- [ ] T001 [P] Write test `tests/Unit/Classification/CeilingStricterTest.php`: `Ceiling::stricter()` over all 16 pairs of the four classes returns the higher rank and is symmetric (SC-001 base)
- [ ] T002 Edit `src/Classification/Ceiling.php` to add `public static function stricter(self $a, self $b): self`
- [ ] T003 [P] Create fixture models in `tests/Fixtures/Classified/`: `DeclaredCustomer` (`#[Classify]` on table `classified_customers`), `UnknownClassCustomer` (class `restricted`), `ParentRecord` / `ChildRecord` (same table) and `OtherTableChild` (different table), `RivalCustomer` (same table as `DeclaredCustomer`, a different class for one column), `StaleCustomer` (declares a dropped column); plus `tests/Fixtures/Classified/DeclaredCustomerContract.php`. Fixtures only, no test

---

## Phase 2: Foundational

**Purpose**: attribute, allowlist, declarations and the merged catalog every story uses.

**Checkpoint**: `EffectiveCatalog` is green in unit tests; no call site uses it yet.

- [ ] T004 [P] Write test `tests/Unit/Classification/ClassifyAttributeTest.php`: `Classify` targets classes only and is not repeatable; it accepts strings and `Ceiling` cases
- [ ] T005 Create `src/Classification/Classify.php` (contract in contracts/)
- [ ] T006 [P] Write test `tests/Unit/Classification/AllowlistedModelsTest.php`: the list is `sources.eloquent.models` plus each configured tool contract's `model()` plus related contracts' models, de-duplicated and sorted; a non-model string is ignored; a model declared with `#[Classify]` but not allowlisted is absent (FR-006)
- [ ] T007 Create `src/Classification/AllowlistedModels.php`
- [ ] T008 [P] Write test `tests/Unit/Classification/ModelDeclarationsTest.php`: `DeclaredCustomer` yields one declaration per column with its table; `UnknownClassCustomer` throws `InvalidArgumentException` naming model and column; a parent's declaration applies to a child on the same table and not to `OtherTableChild`; reading runs no query (`DB::listen` count 0)
- [ ] T009 Create `src/Classification/ModelDeclarations.php` (research R1, R2)
- [ ] T010 [P] Write test `tests/Unit/Classification/EffectiveCatalogTest.php`: for every pair of (model class, config class) including absent, the effective class is the stricter or the only one or absent (SC-001); config less strict than model yields a `config_widens` conflict with subject `classification.<t>.<c>`; two models differing yield `models_disagree` naming both; child less strict than parent yields `child_widens`; `map()` keys are sorted; `configMap()` reads `context-gate.classification`
- [ ] T011 Create `src/Classification/EffectiveCatalog.php` (`configMap()`, `map()`, `catalog()`, `conflicts()`) and bind it as a singleton in `src/ContextGateServiceProvider.php`
- [ ] T012 [P] Write test `tests/Unit/Classification/EffectiveCatalogOnceTest.php`: within one application instance the builder reflects each model once (counting spy on `ModelDeclarations`), and nothing is written to the cache store (FR-013)

---

## Phase 3: User Story 1 - Declare column classes on the model (Priority: P1)

**Goal**: a declaration classifies a column exactly as a config entry would.

**Independent Test**: `DeclaredCustomer` declares `status` internal with no config entry; its contract loads and the
value is returned plain.

- [ ] T013 [P] [US1] Write test `tests/Contract/Classification/ModelDeclaredClassTest.php`: with `DeclaredCustomerContract` as a tool and no config entry for its table, the contract loads and `status` is returned plain; an undeclared column is dropped (US1 #3); with `UnknownClassCustomer` allowlisted the call is refused with `config.invalid` and `gate:check` fails naming model and column (US1 #2, FR-002)
- [ ] T014 [US1] Edit `src/Server/ContractGuard.php`, `src/Server/GateServer.php`, `src/Server/FirewallRunner.php`, `src/Server/Sources/SchemaDescribeTool.php`, `src/Console/CheckCommand.php`, `src/Console/AuditCommand.php` to take the catalog from `app(EffectiveCatalog::class)->catalog()` instead of `new Catalog(config(...))`
- [ ] T015 [P] [US1] Write test `tests/Contract/Classification/SecretDeclaredTest.php` *verification* (FR-014): a model-declared `secret` column listed by a contract refuses the contract, as for a config `secret`; a `personal` column with plain form is refused

**Checkpoint**: US1 works with config map and declarations combined.

---

## Phase 4: User Story 2 - Merge with the configuration map, the stricter class wins (Priority: P1)

**Goal**: stricter wins at runtime; widening and disagreement fail `gate:check`.

**Independent Test**: model `personal`, config `internal` → served as personal, `gate:check` fails naming the key.

- [ ] T016 [P] [US2] Write test `tests/Unit/Readiness/Checks/ModelClassCheckTest.php`: `config_widens` gives FAIL with subject `classification.<t>.<c>` and the text "configuration may not widen a model class"; `models_disagree` FAIL names both models; `child_widens` FAIL names both classes; a stale declaration gives WARN naming model and column (FR-012); no conflict gives PASS; output holds no row value
- [ ] T017 [US2] Create `src/Readiness/Checks/ModelClassCheck.php` and register it in `src/Readiness/CheckRegistry.php`
- [ ] T018 [P] [US2] Write test `tests/Contract/Classification/StricterWinsCallTest.php`: model `personal` with config `internal` serves the column in its personal form and the call is not refused (FR-004 runtime); model `internal` with config `personal` serves personal and `gate:check` passes; agreeing classes report nothing; a config-only column behaves as before (US2 #1–#4)

---

## Phase 5: User Story 3 - CI fails on an unclassified column (Priority: P1)

**Goal**: `gate:scan --ci` per contracts/classify-attribute-and-scan-ci.md.

**Independent Test**: add a column without a class to a fixture table; the CI mode exits 1 naming it.

- [ ] T019 [P] [US3] Write test `tests/Contract/Classification/ScanCiTest.php`: all classified → exit 0; one unclassified column → exit 1 and one `unclassified <Model> <table>.<column>` line (SC-002); a model whose table is missing → exit 1 with `unreadable <Model>` (FR-008); invalid catalog → exit 2; a model with a secondary connection is read through it; routes are not read
- [ ] T020 [P] [US3] Write test `tests/Contract/Classification/ScanCiJsonTest.php`: `--ci --json` stdout parses as one object with `ok`, `unclassified`, `unreadable`, `invalid`, sorted; any diagnostic is on stderr only (FR-009)
- [ ] T021 [US3] Edit `src/Console/ScanCommand.php` to add `--ci` (research R5) and use `AllowlistedModels`; keep the report mode unchanged; edit `src/Console/ClassifyCommand.php` to use `AllowlistedModels`
- [ ] T022 [P] [US3] Write test `tests/Contract/Classification/ScanCiNoValueTest.php`: seed canary rows (`tests/Fixtures/CanaryValues.php`) in the fixture tables; no output of `gate:scan --ci`, `gate:scan --ci --json`, `gate:check` or `gate:classify` contains a canary (SC-005)

---

## Phase 6: User Story 4 - Drafts stay inert until reviewed (Priority: P2)

- [ ] T023 [P] [US4] Write test `tests/Contract/Classification/DraftInertCiTest.php` *verification*: after `gate:classify --force` writes a draft that suggests a class for an unclassified column, `gate:scan --ci` still exits 1 for that column (US4 #1, SC-003) and `gate:check` fails on the marker (US4 #2)
- [ ] T024 [P] [US4] Write test `tests/Contract/Classification/ClassifyNoModelWriteTest.php` *verification*: `gate:classify --force` leaves every file under `tests/Fixtures/Classified` byte-identical (hash before and after) and writes only `app/Gate/classification.php` (US4 #3, FR-010)

---

## Phase 7: User Story 5 - Rule hashes follow the effective catalog (Priority: P2)

- [ ] T025 [P] [US5] Write test `tests/Unit/ContextGateServiceProviderHashTest.php`: changing one `#[Classify]` value (two fixture variants) changes `ruleHashes()['classification']` (SC-004); reordering `sources.eloquent.models` does not; a config change that the model makes irrelevant (config `internal` under model `secret` → `public` under `secret`) does not change it, because the effective map is equal
- [ ] T026 [US5] Edit `src/ContextGateServiceProvider.php` `ruleHashes()` to hash `EffectiveCatalog::map()` (class values as strings) instead of `config('context-gate.classification')`
- [ ] T027 [P] [US5] Write test `tests/Contract/Classification/ReceiptHashTest.php`: the next receipt after a declaration change carries the new `rules.classification` (US5 #1)

---

## Phase 8: Polish and Cross-Cutting

- [ ] T028 [P] Create `docs/classification.md`: the attribute, allowlisted models, stricter-wins table, conflicts, `gate:scan --ci` exit codes and JSON, drafts, inheritance; link from `docs/configuration.md` `classification`
- [ ] T029 [P] Update `README.md` (classification section: declare on the model) and `CHANGELOG.md` (Unreleased: `#[Classify]`, `gate:scan --ci`, catalog hash now covers model declarations)
- [ ] T030 If spec 009 is merged: edit `EffectiveCatalog::configMap()` to read 009's effective classification map, test first in `tests/Unit/Classification/EffectiveCatalogTest.php` (research R7). Otherwise add the note to spec 009's tasks
- [ ] T031 Confirm every new or edited `src/` file stays within 250 lines (`scripts/check-file-length.php`); split `ScanCommand` CI mode into `src/Console/ScanCi.php` if it does not
- [ ] T032 Run `composer style`, `composer stan`, `composer length`, `composer test`; read each exit code; all must be 0
- [ ] T033 Run quickstart.md steps 1–5 on a fixture host app and record the result

---

## Dependencies and Execution Order

- Phase 1 → Phase 2 → stories. T014 (US1) is the switch every later story builds on.
- US2 needs US1's T014. US3 needs Phase 2 only. US4 is independent after Phase 2. US5 needs T011.
- [P] tasks touch different files. Each test task fails before its implementation task (except *verification*).
