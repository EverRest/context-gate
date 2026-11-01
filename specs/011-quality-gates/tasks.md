# Tasks: Quality Gates and Maintenance Hygiene

**Input**: Design documents from `specs/011-quality-gates/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R9), data-model.md. Implement before spec 009 and spec 010.

**Tests**: REQUIRED (constitution IX). Every script is preceded by a failing unit test. Tasks marked *verify* check
work already merged (commit `59777d9`); their test is expected to pass when written.

**Organization**: by user story. US1, US2, US3 are P1. US4, US5 are P2.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- Paths are relative to the repository root

---

## Phase 1: Setup

- [ ] T001 Add `infection/infection` to `require-dev` in `composer.json` (update `composer.lock`); confirm on the installed version that `--filter` accepts a comma-separated list with directory prefixes and file paths (research R2). Record the result in research R2 if the fallback is needed
- [ ] T002 [P] Add `infection.json5` and `build/` to `export-ignore` in `.gitattributes` (confirm `scripts/` and `tests/` already are) and `build/` to `.gitignore`

---

## Phase 2: User Story 1 - Security tests must kill mutants (P1)

**Goal**: CI fails when MSI over the security scope is below 90; no ignore list exists.

**Independent Test**: set `minMsi` above the current score; the job fails and lists survivors by file and line.

- [ ] T003 [P] [US1] Write test `tests/Unit/Scripts/MutationConfigTest.php`: the guard function fails on `ignore` or `ignoreSourceCodeByRegex` in the config, on `@infection-ignore-all` in a `src/` fixture file, on `minMsi` 89, on a missing scope path, on a scope that lacks one FR-002 group, and on `--min-msi` in a composer or workflow fixture; passes on the real repository files
- [ ] T004 [US1] Create `scripts/check-mutation-config.php` (pure function + CLI guard, `MUTATION_FLOOR = 90`) and `infection.json5` (`contextGate.scope` from research R2, `source.directories: ["src"]`, `minMsi: 90`, `timeout` per test, `logs.github: true`, `logs.text: build/infection.log`)
- [ ] T005 [US1] Create `scripts/mutation.php` (reads `contextGate.scope`, runs `vendor/bin/infection --threads=max --show-mutations --no-progress --filter=<scope>`, forwards the exit code) and composer script `mutation`
- [ ] T006 [US1] Run `composer mutation` locally; save the escaped-mutant list (file, line, mutator) as the baseline in the PR description (research R9). Do not commit the log
- [ ] T007 [US1] For each baseline survivor: write the killing test first (in the test file of the owning class under `tests/Unit/` or `tests/Contract/`), see it fail on the mutated code by hand, keep it green on the real code; tag it `#[Group('finding-mutation-<class>-<line>')]` (records are added in T013). Split into one task per owning class when the baseline is known
- [ ] T008 [US1] Add job `mutation` to `.github/workflows/ci.yml` (PHP 8.3, `coverage: pcov`, locked deps, `composer mutation`), on push and pull_request, no path filter
- [ ] T009 [US1] Run `composer mutation` until MSI >= 90 with zero baseline survivors left (FR-010, SC-002)

**Checkpoint**: mutation job green; raising `minMsi` above the score fails it.

---

## Phase 3: User Story 2 - A coverage floor (P1)

**Goal**: CI fails when `src/` line coverage is below 92%.

- [ ] T010 [P] [US2] Write test `tests/Unit/Scripts/CoverageFloorTest.php` with clover fixtures under `tests/Fixtures/coverage/`: 93% passes and prints `line coverage 93.0% (floor 92%)`; 91% fails and prints both numbers; a missing file and a file with 0 statements fail; the summary line is appended to a temp `GITHUB_STEP_SUMMARY`
- [ ] T011 [US2] Create `scripts/coverage-floor.php` (`COVERAGE_FLOOR = 92.0`) and composer script `coverage` (`phpunit --coverage-clover build/coverage/clover.xml` then the floor script)
- [ ] T012 [US2] Run `composer coverage`; if below 92%, add tests for the least-covered `src/` files until it passes (do not lower the floor, FR-004); then add job `coverage` to `.github/workflows/ci.yml` (PHP 8.3, `coverage: pcov`)

---

## Phase 4: User Story 3 - A fixed finding stays fixed (P1)

- [ ] T013 [P] [US3] Write test `tests/Unit/FindingRecordsTest.php`: every key of `tests/findings.php` has at least one test method under `tests/` with `#[Group('finding-<key>')]`; every `finding-*` group used under `tests/` is recorded; a recorded test in an opt-in suite needs `opt_in: true` (data-model Finding record). Start with an empty `tests/findings.php` and one deliberately unrecorded group in a fixture to see it fail
- [ ] T014 [US3] Create `tests/findings.php`; record the existing review tests (`tests/Contract/ReviewFixesRuntimeTest.php`, `tests/Contract/ReviewRound2Test.php`, `tests/Unit/Http/RefusalLedgerLockTest.php`, `tests/Unit/Server/ExtensionPointsTest.php`, `tests/Concurrency/BudgetProcessTest.php` as opt-in) and the T007 kills; add the group attributes to those tests. For each, revert the fix by hand once and note the failing test in `summary` (FR-009)
- [ ] T015 [US3] Add a "Findings" paragraph to the development section of `README.md` (there is no `CONTRIBUTING.md`; do not add one): a fixed finding gets a record and a group in the same change

---

## Phase 5: User Story 4 - Remove compatibility scaffolding (P2) — already satisfied by `59777d9`

- [ ] T016 [P] [US4] *verify*: `docs/upgrading.md` absent; no `upgrading`, `superseded` or `Breaking` in `README.md`, `docs/`, `CHANGELOG.md` (FR-012, FR-013, SC-006). Add this as an assertion in `tests/Unit/Docs/NoUpgradingPageTest.php`
- [ ] T017 [P] [US4] *verify*: `tests/Unit/Server/ExtensionPointsTest.php` covers interface binding wins and concrete-only binding is ignored (FR-014); `docs/extending.md` says the interface is the only extension point. Record it as a finding in `tests/findings.php` (done in T014)

---

## Phase 6: User Story 5 - Source comments state the rule (P2)

- [ ] T018 [P] [US5] Write test `tests/Unit/Scripts/SpecRefsCheckTest.php`: the scan function reports `spec 007`, `FR-004`, `SC-001`, `US3`, `T012`, `research R8`, `R11`, `D36` inside `//`, `/* */` and docblocks of a fixture file, with path and line; ignores the same text inside a string literal or code; ignores files under `tests/`
- [ ] T019 [US5] Create `scripts/check-spec-refs.php` and composer script `refs`; add `@refs` to `composer ci`
- [ ] T020 [P] [US5] Rewrite comments in `src/Pipeline/`, `src/Privacy/`, `src/Policy/`, `src/Classification/`: state the rule, or delete when the code says it (FR-015). `php scripts/check-spec-refs.php` reports none in these directories
- [ ] T021 [P] [US5] Same for `src/Server/` (including `src/Server/Sources/`)
- [ ] T022 [P] [US5] Same for `src/Http/`, `src/Token/`, `src/Support/`, `src/Contract/`, `src/Contracts/`
- [ ] T023 [P] [US5] Same for `src/Source/` (including `Fetch/`, `Pseudo/`) and `src/Runbook/`
- [ ] T024 [P] [US5] Same for `src/Settings/`, `src/Config/`, `src/Readiness/`, `src/Console/`, `src/ContextGateServiceProvider.php`, `src/ContextGate.php`; includes message strings that quote a spec id only if they are comments (user-facing messages such as `SettingsSchema::unknownMessage` are not comments; leave them, see note below)
- [ ] T025 [US5] Add steps `composer length` and `composer refs` to the PHP 8.3 locked leg of the `test` job in `.github/workflows/ci.yml` (research R7: `length` is not in CI today)

Note on T024: `SettingsSchema` messages such as "(spec 007 FR-006)" reach `gate:check` output, not comments. FR-015
covers comments only; changing them is a separate decision (flagged in the report, not done here).

---

## Phase 7: Polish

- [ ] T026 Update `CHANGELOG.md` Unreleased: mutation and coverage gates, finding records, comment check (no behaviour change)
- [ ] T027 [P] Update `README.md` development section and `CLAUDE.md` Gates list with `composer refs`, `composer mutation`, `composer coverage` (the latter two are CI-only, not pre-commit)
- [ ] T028 Run `composer style`, `composer stan`, `composer length`, `composer refs`, `composer test`; read each exit code; all 0. Confirm `composer test` did not start Infection or coverage (SC-007)

---

## Dependencies and Execution Order

- T001 before T004–T009. T003 before T004; T004 before T005; T006 before T007; T007 before T009; T008 after T005.
- US2 (T010–T012) and US5 (T018–T025) are independent of US1. T013 before T014; T014 needs T007's groups.
- T020–T024 run in parallel; T025 after all of them.
- Phase 7 last.
