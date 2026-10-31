# Tasks: Context Firewall

**Input**: Design documents from `specs/002-context-firewall/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R13), data-model.md, contracts/firewall-pipeline.md,
quickstart.md. Spec 001 must be complete.

**Tests**: REQUIRED (constitution IX). Every test task precedes its implementation task and must fail
first. The privacy suite with seeded personal data and canary values is written first (Phase 2). No test
may touch the network.

**Organization**: Grouped by user story. US1, US2 and US3 are P1 (MVP). US4–US8 are P2 and P3.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1…US8 from spec.md
- Paths are relative to the repository root

---

## Phase 1: Setup

- [ ] T001 Add `"ext-intl": "*"` to the `require` section of `composer.json` (research R7, constitution X)
- [ ] T002 [P] Create `tests/Fixtures/SeededPii.php` with constants for one email, one E.164 phone, one IBAN, one card number that passes Luhn, one bearer token, each unique and documented as fake
- [ ] T003 [P] Create `tests/Fixtures/CanaryValues.php` with `canary-7f3a@example.invalid` and `CANARY_TOKEN_0c9e1a` for tests
- [ ] T004 [P] Create `tests/Fixtures/InjectionCorpus.php` with 10 injection phrases and 5 strings containing U+200B, U+202E, U+2060, U+FEFF, U+E0041
- [ ] T005 Run `composer install` and confirm `php -m | grep intl` succeeds and the gates from spec 001 stay green

---

## Phase 2: Foundational

**Purpose**: Shared types every story uses. The privacy suite is written here so every later story is
checked against it.

- [ ] T006 [P] Write test `tests/Unit/Classification/CeilingTest.php`: order is `public` < `internal` < `personal` < `secret`; `Ceiling::allows(class, ceiling)` is true only when class ≤ ceiling
- [ ] T007 Create `src/Classification/Ceiling.php` as a backed enum with the four cases and `allows()`
- [ ] T008 [P] Write test `tests/Unit/Classification/CatalogTest.php`: a catalog value outside `public|internal|personal|secret` throws; a valid catalog returns the class for a table and column; a missing column returns null
- [ ] T009 Create `src/Classification/Catalog.php` that loads `app/Gate/classification.php` and validates each class (data-model Classification)
- [ ] T010 [P] Write test `tests/Unit/Contract/FieldTest.php`: `Field::relation()` accepts only a contract class name; `Field::plain()` is refused for `personal`; each factory returns the form named in data-model Field
- [ ] T011 Create `src/Contract/Field.php` with the factories `pseudonym`, `bucket`, `date`, `aggregate`, `text`, `relation`, `plain`
- [ ] T012 [P] Write test `tests/Unit/Contract/ContextContractTest.php`: `fields()` returns fields in declared order; the class has no way to set a classification (no parameter named `class` on any factory)
- [ ] T013 Create `src/Contract/ContextContract.php` as an abstract class with `fields(): array` and `model(): string`
- [ ] T014 [P] Write test `tests/Unit/Pipeline/StageTest.php`: a stage receives the payload and the next closure and can short-circuit by returning without calling next
- [ ] T015 Create `src/Pipeline/Stage.php` (interface `handle(Payload $payload, Closure $next): Payload`) and `src/Pipeline/Payload.php` (rows, fields, flags, dropped list, byte count)
- [ ] T016 [P] Write test `tests/Unit/Pipeline/CapsTest.php`: `Caps::PER_CALL` is 65536, `PER_SESSION` is 2097152, `PER_DAY` is 20971520, `PER_MINUTE` is 300 (data-model Budget counters)
- [ ] T017 Create `src/Pipeline/Caps.php` with those four constants
- [ ] T018 [P] Write test `tests/Unit/Contract/RuleHashTest.php`: a hash is 6 hex characters; identical input gives identical output; a changed field form gives a different hash (research R11)
- [ ] T019 Create `src/Contract/RuleHash.php` with `of(string $canonical): string` returning the first six hex characters of sha256
- [ ] T020 [P] Write privacy suite `tests/Contract/PrivacySuiteTest.php`: a tool returns a row containing every value from SeededPii and a canary; the test asserts none of them appears in the response or in the receipt (SC-001, SC-003). The test is expected to fail until Phase 3 and 8 are complete

**Checkpoint**: Foundation ready. T020 is intentionally red until the pipeline is complete.

---

## Phase 3: User Story 1 - Only contract fields leave the server (Priority: P1)

**Goal**: Projection by contract; unclassified fields dropped; contract load checks.

**Independent Test**: Contract with two of four fields and a row with five values, one unclassified → two fields returned.

### Tests for User Story 1

- [ ] T021 [P] [US1] Write test `tests/Unit/Pipeline/ProjectorTest.php`: only contract fields survive; order follows the contract; a field absent from the row is omitted without error
- [ ] T022 [P] [US1] Write test `tests/Unit/Pipeline/ClassifyCheckTest.php`: a field with no catalog class is dropped and listed with reason `field.unclassified` (FR-003)
- [ ] T023 [P] [US1] Write test `tests/Unit/Contract/ContractValidatorTest.php`: a contract referring to an unclassified column fails to load (FR-004); the error names the column
- [ ] T024 [P] [US1] Write test `tests/Contract/ProjectionTest.php`: tool response contains exactly the contract fields (User Story 1, scenario 1)

### Implementation for User Story 1

- [ ] T025 [US1] Create `src/Contract/ContractValidator.php` that checks every field against the Catalog on load
- [ ] T026 [US1] Create `src/Pipeline/ClassifyCheck.php` implementing `Stage`; drops unclassified fields and records them in the payload
- [ ] T027 [US1] Create `src/Pipeline/Projector.php` implementing `Stage`; keeps only contract fields in order
- [ ] T028 [US1] Wire `ClassifyCheck` and `Projector` into `src/Server/GateCallTool.php` after the handler call (research R1 stages 2–3)
- [ ] T029 [US1] Verify T021–T024 pass (T024 may still be red until US2 and US3 are done; the projection assertion must pass)

**Checkpoint**: US1 works without relations, pseudonyms or redaction.

---

## Phase 4: User Story 2 - Secret and personal values never leave raw (Priority: P1)

**Goal**: `secret` never returned; `personal` only transformed; load-time rejection.

**Independent Test**: Row with email, password hash and total; contract lists all three; password absent; email as pseudonym; total as bucket.

### Tests for User Story 2

- [ ] T030 [P] [US2] Write test `tests/Unit/Contract/SecretFieldTest.php`: a contract listing a `secret` column fails to load; the message names the field and the rule (User Story 2, scenario 1)
- [ ] T031 [P] [US2] Write test `tests/Unit/Contract/PersonalFormTest.php`: a `personal` field with a plain form fails to load; with pseudonym, aggregate or bucket it loads (User Story 2, scenarios 2–3)
- [ ] T032 [P] [US2] Write test `tests/Contract/SecretNeverReturnedTest.php`: a row with a seeded `password` value → no response contains the value in any form (SC-001)

### Implementation for User Story 2

- [ ] T033 [US2] Extend `ContractValidator` with the `secret` and `personal`-form rules (FR-005, FR-006)
- [ ] T034 [US2] Verify T030–T032 pass

---

## Phase 5: User Story 3 - Pseudonyms are stable within scope and resolve only on the server (Priority: P1)

**Goal**: Keyed pseudonyms per scope; server-side resolution; unknown pseudonyms refused.

**Independent Test**: Two calls in one session give the same pseudonym; a new session gives a different one; an unknown pseudonym argument is refused.

### Tests for User Story 3

- [ ] T035 [P] [US3] Write test `tests/Unit/Privacy/PseudonymizerTest.php`: same record and scope give the same value; different scope gives a different value; output length prefix plus 10 hex characters (research R4)
- [ ] T036 [P] [US3] Write test `tests/Unit/Privacy/PseudonymScopeTest.php`: session scope key lives in memory; day scope key is stored encrypted with TTL 86400; week scope TTL 604800; a scope above 7 days is rejected (FR-009)
- [ ] T037 [P] [US3] Write test `tests/Unit/Privacy/PseudonymCollisionTest.php`: when two records produce the same short value in a scope, the second gets one extra hex character and both stay unique (edge case)
- [ ] T038 [P] [US3] Write test `tests/Contract/PseudonymArgumentTest.php`: an unknown pseudonym argument returns `Request refused.` and receipt `pseudonym.unknown` with no record identifier (User Story 3, scenario 3)
- [ ] T039 [P] [US3] Write test `tests/Unit/Privacy/PseudonymKeyFailureTest.php`: when key generation fails, the call is refused with `pseudonym.keyfail` and no pseudonym is returned (edge case)

### Implementation for User Story 3

- [ ] T040 [US3] Create `src/Privacy/Pseudonymizer.php` with HMAC-SHA256 derivation, scope handling, and the reverse map (research R4, R5)
- [ ] T041 [US3] Create `src/Pipeline/Transformer.php` implementing `Stage`; applies `pseudonym`, `bucket`, `date` and `aggregate` forms
- [ ] T042 [US3] Extend `src/Server/GateCallTool.php` `ValidateInput` path to resolve pseudonym arguments through `Pseudonymizer` (research R5)
- [ ] T043 [US3] Verify T035–T039 pass

**Checkpoint**: P1 stories complete (US1–US3). Privacy suite T020 can turn green for the fields covered so far.

---

## Phase 6: User Story 4 - Related records are limited to one level (Priority: P2)

**Goal**: Relations only through the related contract; depth exactly one; ceiling applies to related fields.

**Independent Test**: Order → customer (through customer contract) works; customer → address relation fails at load.

### Tests for User Story 4

- [ ] T044 [P] [US4] Write test `tests/Unit/Contract/RelationDepthTest.php`: a related contract with a relation fails to load with `contract.depth` (FR-011)
- [ ] T045 [P] [US4] Write test `tests/Unit/Pipeline/RelationsTest.php`: related rows are loaded in one query per relation (eager loading asserted with a query counter) (FR-012)
- [ ] T046 [P] [US4] Write test `tests/Contract/RelationCeilingTest.php`: with ceiling `internal`, a `personal` field of the related record appears only as a pseudonym (User Story 4, scenario 3)

### Implementation for User Story 4

- [ ] T047 [US4] Extend `ContractValidator` with the depth rule
- [ ] T048 [US4] Create `src/Pipeline/Relations.php` implementing `Stage`; eager loads related rows through the related contract; counts related rows toward the row limit
- [ ] T049 [US4] Add the caller ceiling check (`src/Pipeline/CeilingCheck.php`, implementing `Stage`) that drops fields above the ceiling with `ceiling.exceeded` (FR-007, research R12); run it on related fields too
- [ ] T050 [US4] Verify T044–T046 pass

---

## Phase 7: User Story 5 - Canary values block a response (Priority: P2)

**Goal**: Canary values checked in every normalized response and argument; match blocks.

**Independent Test**: Canary placed in a returned fixture → `Request failed. Reference: r_...` and `canary.hit`.

### Tests for User Story 5

- [ ] T051 [P] [US5] Write test `tests/Unit/Pipeline/CanaryCheckTest.php`: a canary inside normalized output is detected; a canary split by U+200B is detected after normalization; no canary configured → no blocking (research R8)
- [ ] T052 [P] [US5] Write test `tests/Contract/CanaryBlockTest.php`: canary in response → generic failure, receipt `canary.hit`, log contains no canary value (User Story 5, scenario 2; FR-024)
- [ ] T053 [P] [US5] Write test `tests/Contract/CanaryArgumentTest.php`: canary in arguments → call refused and event recorded (User Story 5, scenario 3)

### Implementation for User Story 5

- [ ] T054 [US5] Create `src/Pipeline/CanaryCheck.php` implementing `Stage`
- [ ] T055 [US5] Verify T051–T053 pass

---

## Phase 8: User Story 6 - Untrusted text and injection flags (Priority: P2)

**Goal**: NFKC, invisible characters removed, redaction per field, untrusted wrapper, injection flag without blocking.

**Independent Test**: Text with U+200B and "ignore previous instructions" → character removed, wrapper with source, flag `injection.phrase`, not blocked.

### Tests for User Story 6

- [ ] T056 [P] [US6] Write test `tests/Unit/Pipeline/NormalizerTest.php`: fullwidth `ＡＢＣ` becomes `ABC` (NFKC); invisible characters from InjectionCorpus are removed (FR-013)
- [ ] T057 [P] [US6] Write test `tests/Unit/Privacy/RedactorTest.php`: SeededPii values are replaced by their placeholder; a Luhn-invalid 16-digit number is not replaced; an exception on one field drops only that field (FR-014, `redaction.failed`)
- [ ] T058 [P] [US6] Write test `tests/Unit/Pipeline/QuarantineTest.php`: text fields are wrapped as `{untrusted: true, source, text}` (FR-015); each InjectionCorpus phrase sets the flag; the response is not blocked (FR-016)
- [ ] T059 [P] [US6] Write test `tests/Contract/PrivacySeededTextTest.php`: SeededPii inside text fields never appears in the response (SC-001)

### Implementation for User Story 6

- [ ] T060 [US6] Create `src/Pipeline/Normalizer.php` implementing `Stage`; uses `Normalizer::normalize(NFKC)` then removes the invisible-character set from research R9
- [ ] T061 [US6] Create `src/Privacy/Redactor.php` with the regular-expression layer (email, phone, IBAN, Luhn-checked card, bearer token) (research R7)
- [ ] T062 [US6] Create `src/Pipeline/Redact.php` implementing `Stage`; calls `Redactor` per text field and drops a field on exception (`redaction.failed`)
- [ ] T063 [US6] Create `src/Pipeline/Quarantine.php` implementing `Stage`; wraps text and sets `injection.phrase` flags
- [ ] T064 [US6] Verify T056–T059 pass

---

## Phase 9: User Story 7 - Volume limits per call, session, day and minute (Priority: P2)

**Goal**: Truncation per call; refusal per session, per token day and per minute; caps enforced in code.

**Independent Test**: 20 KB response truncated; session overrun refused; 61st call in a minute refused.

### Tests for User Story 7

- [ ] T065 [P] [US7] Write test `tests/Unit/Pipeline/BudgetTest.php`: a 20 KB output is truncated at a record boundary with `truncated: true`; the default per-call limit is 16384 bytes (16 KiB) (FR-018)
- [ ] T066 [P] [US7] Write test `tests/Unit/Pipeline/SessionBudgetTest.php`: after 400 KiB in one session the next call is refused with `budget.session` (FR-019)
- [ ] T067 [P] [US7] Write test `tests/Unit/Pipeline/DailyBudgetTest.php`: after 5 MiB in one UTC day the call is refused with `budget.daily`; the counter TTL ends at 00:00 UTC (FR-020)
- [ ] T068 [P] [US7] Write test `tests/Contract/RateLimitTest.php`: 60 calls pass and the 61st returns `rate.limited` with a retry time (SC-006, FR-021)
- [ ] T069 [P] [US7] Write test `tests/Unit/Config/CapsValidationTest.php`: a configured value above any cap fails `gate:check` and names the key (FR-022, FR-025)

### Implementation for User Story 7

- [ ] T070 [US7] Create `src/Pipeline/Budget.php` implementing `Stage`; per-call truncation, session and day counters in the cache store, minute limit via `RateLimiter`
- [ ] T071 [US7] Verify T065–T069 pass

---

## Phase 10: User Story 8 - Receipts show the rules in force (Priority: P3)

**Goal**: Receipt carries `rules.tool`, `rules.contract`, `rules.classification`, computed once per process.

**Independent Test**: Change the catalog → receipt hash changes; no change → same hashes.

### Tests for User Story 8

- [ ] T072 [P] [US8] Write test `tests/Unit/Server/RulesInReceiptTest.php`: each receipt contains the three keys with 6-character values (FR-023)
- [ ] T073 [P] [US8] Write test `tests/Contract/RuleChangeTest.php`: changing the catalog changes `rules.classification`; two calls without changes give identical hashes (SC-007)
- [ ] T074 [P] [US8] Write test `tests/Unit/Server/ReceiptNoValuesTest.php`: no value from SeededPii or canaries appears in any receipt line (FR-024)

### Implementation for User Story 8

- [ ] T075 [US8] Extend `src/Server/Receipt.php` with `rules`, `dropped`, `truncated`, `flags` fields (data-model Receipt)
- [ ] T076 [US8] Compute the three rule hashes once in `ContextGateServiceProvider::boot()` and pass them to `Receipt` (research R11)
- [ ] T077 [US8] Verify T072–T074 pass

---

## Phase 11: Pipeline wiring, gate check and Polish & Cross-Cutting Concerns

- [ ] T086 Wire the full pipeline in `src/Server/GateCallTool.php`: run the stages in the fixed order of
  `contracts/firewall-pipeline.md` (fetch, classify check, project, relations, transform, normalize, redact,
  canary, quarantine, budget, receipt). Each stage is a `Stage` class from US1–US8. A test
  `tests/Contract/PipelineOrderTest.php` records the stage calls and asserts the order, and fails if a stage is
  skipped. Runs after all story phases and before T080. Budget signals through `Payload::$flags`: map
  `budget.call` to `truncated: true` in the response, and `budget.session`, `budget.daily` and `rate.limited`
  to a refusal; `rate.limited` also carries the retry time from `Budget::$retryAfter`.

- [ ] T078 Extend `src/Console/CheckCommand.php` (create it if spec 001 did not) so `gate:check` loads contracts and catalog and fails on every rule in FR-025 (research R13)
- [ ] T079 [P] Write test `tests/EndToEnd/GateCheckTest.php`: each seeded configuration error from SC-005 makes `gate:check` exit 1 and name the problem
- [ ] T080 Make T020 (privacy suite) pass end to end; fix only what it reports
- [ ] T081 [P] Run quickstart.md sections 1–7 on a fresh application; record results in `specs/002-context-firewall/checklists/requirements.md`
- [ ] T082 [P] Update `README.md` with the catalog and contract steps from quickstart.md
- [ ] T083 [P] Update `CHANGELOG.md` `Unreleased` section
- [ ] T084 Run `php scripts/check-file-length.php` and split any file over the limit
- [ ] T085 Run `composer test`, `composer stan` (level 8), `composer style`; all must exit 0

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 → US1 (P1) → US2 (P1) → US3 (P1).
- US4 depends on US1 (projection) and US3 (pseudonyms for the ceiling case).
- US5, US6 and US7 depend on the pipeline stages from US1–US3 but not on each other; they can run in parallel.
- US8 depends on the Receipt extension and the catalog (Phase 2 and US1).
- Polish depends on all stories. T020 turns green only after US1–US8.

## Parallel Examples

- Phase 2: T006, T008, T010, T012, T014, T016, T018 and T020 can run in parallel (separate test files).
- US5: T051, T052 and T053 in parallel.
- US6: T056–T059 in parallel.
- US7: T065–T069 in parallel.

## Implementation Strategy

**MVP**: Phases 1–5 (US1–US3). A response that is projected, classified, free of secrets, with stable
pseudonyms. This is the minimum before any data source from spec 003 may ship.

**Incremental**: then US5 (canaries, cheap and high value), US6, US7, US4, US8, Polish.

---

## Notes

- Constitution principle IX: write each test first, run it, see it fail, then implement.
- Do not add a runtime dependency beyond `ext-intl` (justified in plan.md).
- T020 is the privacy gate: it must be green before any spec-003 source is merged.
