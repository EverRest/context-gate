# Tasks: Release Readiness

**Input**: Design documents from `specs/006-readiness/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R8), data-model.md, contracts/readiness-commands.md,
quickstart.md. Specs 001–005 must be complete.

**Tests**: REQUIRED (constitution IX). Every check has a failing fixture test before its implementation.

**Organization**: Grouped by user story. US1 (audit) and US2 (doctor) are P1; US3 (docs) and US4 (install test)
are P2; US5 (release) is P3.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup

- [ ] T001 Create `src/ContextGate.php` with `const VERSION = '0.1.0';` in namespace `ContextGate` (research R7)
- [ ] T002 [P] Create folder `src/Readiness/Checks/` and empty `src/Readiness/CheckRegistry.php` class with an empty `all(): array` method
- [ ] T003 [P] Create folder `docs/` with placeholder files `docs/installation.md`, `docs/configuration.md`, `docs/tokens.md`, `docs/privacy.md` each containing a level-one heading only
- [ ] T004 [P] Copy `/Users/pavlo/code/watcher/.github/workflows/release.yml` to `.github/workflows/release.yml` (the file already exists from the scaffold; replace it with the adapted version in T070)
- [ ] T005 Add the `0.1.0` heading `## [0.1.0]` to `CHANGELOG.md` with an empty body under it

---

## Phase 2: Foundational

**Purpose**: The check contract. Every later check depends on it.

- [ ] T006 [P] Write test `tests/Unit/Readiness/CheckResultTest.php`: `CheckResult::fail(subject, message, fix)` has status `fail`; the message field never contains a value passed as a secret argument (FR-014); `pass` and `warn` constructors exist
- [ ] T007 Create `src/Readiness/CheckResult.php` with `pass`, `warn`, `fail` constructors and readonly properties from data-model CheckResult
- [ ] T008 [P] Write test `tests/Unit/Readiness/CheckRegistryTest.php`: `CheckRegistry::all()` returns every check class from `src/Readiness/Checks/`; no duplicates; each implements `Check`
- [ ] T009 Create `src/Readiness/Check.php` (interface: `name()`, `run(ReadinessContext $ctx): CheckResult`, `fixHint(): string`) and `src/Readiness/ReadinessContext.php` (config, catalog, contracts, token table access)
- [ ] T010 Complete `src/Readiness/CheckRegistry.php` to return the check list once the checks exist (T025+)

**Checkpoint**: Check contract is green; checks are added one at a time in US2.

---

## Phase 3: User Story 1 - See everything that is exposed (Priority: P1)

**Goal**: `gate:audit` lists enabled tools with risk, ceiling, classes, contract, hashes; disabled tools by name; `--json`; no values; no writes.

**Independent Test**: Two enabled tools and one disabled; text and JSON match; no value; no file changed.

### Tests for User Story 1

- [ ] T011 [P] [US1] Write test `tests/Contract/AuditListTest.php`: two enabled tools appear with risk, ceiling, field classes, contract name and three rule hashes; one disabled tool appears only under `Not enabled:` (User Story 1 scenarios 1–2; SC-001)
- [ ] T012 [P] [US1] Write test `tests/Contract/AuditJsonTest.php`: `--json` parses and carries the same tools and classes as the text output (User Story 1 scenario 3; FR-003)
- [ ] T013 [P] [US1] Write test `tests/Contract/AuditNoValueTest.php`: a seeded canary and a seeded personal value in the fixture never appear in audit output (FR-004)
- [ ] T014 [P] [US1] Write test `tests/Contract/AuditReadOnlyTest.php`: file modification times and the database row count are unchanged after `gate:audit` (User Story 1 scenario 4)
- [ ] T015 [P] [US1] Write test `tests/Unit/Readiness/AuditEntryTest.php`: an audit entry for a tool with no enabled flag produces only `tool` and `enabled: false`

### Implementation for User Story 1

- [ ] T016 [US1] Create `src/Readiness/Audit.php` building `AuditEntry` objects from the tool registry (spec 001), contracts (spec 002) and rule hashes (spec 002 R11)
- [ ] T017 [US1] Create `src/Console/AuditCommand.php` for `gate:audit [--json]` printing text and JSON from `Audit`
- [ ] T018 [US1] Register `gate:audit` in `src/ContextGateServiceProvider.php`
- [ ] T019 [US1] Verify T011–T015 pass

---

## Phase 4: User Story 2 - Check the installation before it serves traffic (Priority: P1)

**Goal**: `gate:check` runs every check from specs 002–005 plus environment checks; exit 0 only when all pass; each failure names its subject and a fix.

**Independent Test**: Introduce one error per check; each run exits non-zero and names it; a correct installation exits 0.

### Tests for User Story 2 (one failing fixture per check, written first)

- [ ] T020 [P] [US2] Write test `tests/Unit/Readiness/Checks/CapsCheckTest.php`: a per-call limit above 65536 fails and names `context-gate.firewall.limits.per_call_bytes` (spec 002 FR-022)
- [ ] T021 [P] [US2] Write test `tests/Unit/Readiness/Checks/CatalogCheckTest.php`: a contract column missing from the catalog fails naming the column; a catalog line containing `// needs decision` fails naming `app/Gate/classification.php` and the line (User Story 2 scenario 3)
- [ ] T022 [P] [US2] Write test `tests/Unit/Readiness/Checks/ContractCheckTest.php`: a contract listing a `secret` field fails; a relation of depth two fails (spec 002 FR-004, FR-011)
- [ ] T023 [P] [US2] Write test `tests/Unit/Readiness/Checks/CanaryCheckTest.php`: canaries enabled with an empty value fails naming `context-gate.firewall.canaries.values` (User Story 2 scenario 4; FR-008)
- [ ] T024 [P] [US2] Write test `tests/Unit/Readiness/Checks/ExtensionsCheckTest.php`: with `ext-intl` or `symfony/yaml` unavailable (simulated class/extension lookup) the check fails naming the missing item (FR-009)
- [ ] T025 [P] [US2] Write test `tests/Unit/Readiness/Checks/OriginCheckTest.php`: `https://internal.example.com/path` fails; `https://internal.example.com:8443` passes (User Story 2 scenario 6; FR-010)
- [ ] T026 [P] [US2] Write test `tests/Unit/Readiness/Checks/TokenTableCheckTest.php`: a missing `gate_token_issuers` table fails naming the table; an empty table passes with a note (edge case)
- [ ] T027 [P] [US2] Write test `tests/Unit/Readiness/Checks/TransportCheckTest.php`: `APP_ENV=production` with stdio the only transport fails with the production rule (User Story 2 scenario 5; FR-012); with HTTP configured it passes
- [ ] T028 [P] [US2] Write test `tests/Unit/Readiness/Checks/UrlAllowlistCheckTest.php`: an allowlist entry with a scheme or a path fails naming the entry (spec 004)
- [ ] T029 [P] [US2] Write test `tests/Unit/Readiness/Checks/AbilitySetCheckTest.php`: an existing token with an ability outside the package set fails naming the token id and ability (FR-011); no token value is printed
- [ ] T030 [P] [US2] Write test `tests/EndToEnd/CheckCorrectInstallTest.php`: on a correct fixture installation every check passes and the command exits 0 (User Story 2; SC-003)
- [ ] T031 [P] [US2] Write test `tests/Unit/Readiness/CheckNoValueTest.php`: seeded config values and canaries never appear in any check output (FR-014)

### Implementation for User Story 2

- [ ] T032 [P] [US2] Create `src/Readiness/Checks/CapsCheck.php` (T020)
- [ ] T033 [P] [US2] Create `src/Readiness/Checks/CatalogCheck.php` (T021)
- [ ] T034 [P] [US2] Create `src/Readiness/Checks/ContractCheck.php` (T022)
- [ ] T035 [P] [US2] Create `src/Readiness/Checks/CanaryCheck.php` (T023)
- [ ] T036 [P] [US2] Create `src/Readiness/Checks/ExtensionsCheck.php` (T024)
- [ ] T037 [P] [US2] Create `src/Readiness/Checks/OriginCheck.php` (T025)
- [ ] T038 [P] [US2] Create `src/Readiness/Checks/TokenTableCheck.php` (T026)
- [ ] T039 [P] [US2] Create `src/Readiness/Checks/TransportCheck.php` (T027)
- [ ] T040 [P] [US2] Create `src/Readiness/Checks/UrlAllowlistCheck.php` (T028)
- [ ] T041 [P] [US2] Create `src/Readiness/Checks/AbilitySetCheck.php` (T029)
- [ ] T042 [US2] Register all checks in `src/Readiness/CheckRegistry.php` (completes T010)
- [ ] T043 [US2] Create `src/Console/CheckCommand.php` for `gate:check [--json]`: prints `PASS|FAIL|WARN subject: message` and `fix:` lines; exit 0 only with no FAIL (contract)
- [ ] T044 [US2] Register `gate:check` in `src/ContextGateServiceProvider.php`, replacing the partial command from spec 002
- [ ] T045 [US2] Verify T020–T031 pass

---

## Phase 5: User Story 3 - Read the documentation for each audience (Priority: P2)

**Goal**: Four documents written; configuration reference in sync with the config file; SECURITY.md with threat model and reporting.

**Independent Test**: The config-sync test passes; each documented command exists; SECURITY.md has the reporting section.

### Tests for User Story 3

- [ ] T046 [P] [US3] Write test `tests/Contract/DocsConfigSyncTest.php`: every key in `config/context-gate.php` appears in `docs/configuration.md`, and every key documented exists in the file (User Story 3 scenario 2; SC-004; R6)
- [ ] T047 [P] [US3] Write test `tests/Contract/DocsCommandsTest.php`: every `php artisan gate:*` command mentioned in `docs/` is registered in the application
- [ ] T048 [P] [US3] Write test `tests/Contract/SecurityMdTest.php`: `SECURITY.md` contains the sections Threat model, Scope, Supported versions, Reporting with a response time (FR-016)

### Implementation for User Story 3

- [ ] T049 [US3] Write `docs/installation.md` (composer require, `gate:install`, `gate:check`, quickstart of spec 001) (FR-015)
- [ ] T050 [US3] Write `docs/configuration.md` with one section per key from the config file (T046 must pass)
- [ ] T051 [US3] Write `docs/tokens.md` (issue, list, revoke, 90-day maximum, one-time display, ceiling abilities) (FR-015)
- [ ] T052 [US3] Write `docs/privacy.md` (classes, pipeline stages, canaries, what is never sent) (FR-015)
- [ ] T053 [US3] Write `SECURITY.md` with the sections from T048 and the placeholder `<REPORTING_ADDRESS>` (research R8)
- [ ] T054 [US3] Verify T046–T048 pass

---

## Phase 6: User Story 4 - Install on Laravel 12 and 13 (Priority: P2)

**Goal**: A script installs the package on a fresh skeleton for each version and runs `gate:install`, `gate:check` and the spec 001 quickstart.

**Independent Test**: `scripts/install-test.sh 12` and `13` both print the three ok lines.

### Tests for User Story 4

- [ ] T055 [P] [US4] Write test `tests/EndToEnd/InstallMatrixTest.php` that skips unless `CONTEXT_GATE_INSTALL_TEST=1`, runs the script for each version, and asserts `install ok`, `gate:check ok`, `quickstart ok` (User Story 4 scenarios 1–2; SC-005)
- [ ] T056 [P] [US4] Write test `tests/Unit/Readiness/ComposerConstraintTest.php`: `composer.json` allows `laravel/framework` `^12.0||^13.0` and rejects `^11.0` (User Story 4 scenario 3; FR-017)

### Implementation for User Story 4

- [ ] T057 [US4] Create `scripts/install-test.sh <version>` that creates a skeleton in a temp directory, adds the package from the local path, runs the three steps, and removes the temp directory (research R4)
- [ ] T058 [US4] Add a CI matrix job `install-test` for versions `12` and `13` in `.github/workflows/ci.yml`
- [ ] T059 [US4] Verify T055–T056 pass

---

## Phase 7: User Story 5 - Prepare the release without publishing it (Priority: P3)

**Goal**: Release workflow with dry-run default and guards: changelog heading, version constant, reporting placeholder.

**Independent Test**: Dry-run builds the archive and fails on placeholder; no publication step is reachable without `publish: true`.

### Tests for User Story 5

- [ ] T060 [P] [US5] Write test `tests/Contract/VersionTest.php`: `ContextGate::VERSION` equals the heading in `CHANGELOG.md` (FR-018, SC-006)
- [ ] T061 [P] [US5] Write test `tests/Contract/ReleaseGuardTest.php`: a copy of the changelog without the heading fails the guard script; a `SECURITY.md` containing `<REPORTING_ADDRESS>` fails the placeholder guard (User Story 5 scenarios 2 and 3; FR-019)
- [ ] T062 [P] [US5] Write test `tests/Contract/ReleaseWorkflowTest.php`: parses `.github/workflows/release.yml`; the `publish` input defaults to `false`; the release-creation step has a condition on `inputs.publish == 'true'` (FR-020)

### Implementation for User Story 5

- [ ] T063 [US5] Create `scripts/check-release.sh` implementing the changelog, version and placeholder guards (T061)
- [ ] T064 [US5] Adapt `.github/workflows/release.yml`: `workflow_dispatch` with `tag` and `publish` (default `false`), archive `context-gate-<tag>.tar.gz` via `git archive`, provenance attestation, upload; release creation only under `publish == 'true'` (contract, R5)
- [ ] T065 [US5] Verify T060–T062 pass

---

## Phase 8: Polish & Cross-Cutting Concerns

- [ ] T066 [P] Write `README.md` updates: link to the four docs, quick start, supported versions 12 and 13
- [ ] T067 [P] Finalize the `CHANGELOG.md` 0.1.0 body with all features from specs 001–005 (one bullet each)
- [ ] T068 Run `php scripts/check-file-length.php` and split any file over the limit
- [ ] T069 Run quickstart.md sections 1–6; record results in `specs/006-readiness/checklists/requirements.md`
- [ ] T070 Run `composer test`, `composer stan` (level 8), `composer style`; all must exit 0
- [ ] T071 Do not run the release workflow with `publish: true`. Confirm with the maintainer before any publication (FR-020)

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 (check contract) → US1 (audit, uses the registry types) → US2 (checks registered in T042) →
  US3 (docs; config-sync needs the final config) → US4 (install test needs gate:check) → US5 (release) → Polish.
- US1 and US2 are P1 and can run in parallel after Phase 2 (different files).
- US3 and US4 are independent after US2. US5 depends on US3 (SECURITY.md placeholder) and CHANGELOG (T005).

## Parallel Examples

- US2: T020–T031 in parallel (separate test files); then T032–T041 in parallel (separate check classes).
- US3: T046–T048 in parallel.
- US5: T060–T062 in parallel.

## Implementation Strategy

**MVP**: Phases 1–4 (audit and doctor). These two make the installation auditable and checkable.

**Incremental**: then US3 (docs), US4 (install on two versions), US5 (release workflow), Polish. The release
itself (T071) is not part of any phase: it waits for the maintainer.

---

## Notes

- Constitution principle IX: write each test first, run it, see it fail, then implement.
- No check prints a value (FR-014); tests T013 and T031 guard it.
- Publication is excluded by FR-020: `publish` stays `false` in every task.
