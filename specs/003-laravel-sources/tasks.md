# Tasks: Laravel Sources

**Input**: Design documents from `specs/003-laravel-sources/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R8), data-model.md, contracts/sources.md, quickstart.md.
Specs 001 and 002 must be complete.

**Tests**: REQUIRED (constitution IX). Tests precede implementation. The adversarial path-policy test set is
written first (Phase 2), because docs access is the highest-risk source.

**Organization**: Grouped by user story. US1 and US2 are P1; US3 and US4 are P2.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup

- [x] T001 Create fixture application `tests/Fixtures/app/` with a minimal `config/`, `routes/api.php`, `app/Models/User.php` and `app/Models/Order.php`
- [x] T002 [P] Add to `tests/Fixtures/app/docs/guide.md` a normal Markdown file with the text `Order status guide.`
- [x] T003 [P] Create symlink `tests/Fixtures/app/docs/link-to-env` pointing to `../.env` and `tests/Fixtures/app/docs/link-outside` pointing to `../../outside.md`; git stores symlinks natively, so no `.gitattributes` entry is needed
- [ ] T004 [P] Create `tests/Fixtures/app/.env` (`.gitignore` exception added for this path) with a fake `APP_KEY=base64:FAKE_FIXTURE_ONLY` and `tests/Fixtures/app/outside.md` with `outside the root`
- [x] T005 Add the fixture application to `phpunit.xml.dist` as the test base path used by `tests/Contract` and `tests/EndToEnd` for this feature

---

## Phase 2: Foundational

**Purpose**: Shared pieces. The adversarial path-policy set is written first and drives DocsPathPolicy.

- [x] T006 [P] Write adversarial test `tests/Unit/Source/DocsPathPolicyAdversarialTest.php` with cases: `../.env`, `docs/../.env`, `docs/.env`, `docs/.hidden.md`, `DOCS/guide.md` on case-insensitive paths, symlink `docs/link-to-env` (refused at step `realpath` or `denylist`), symlink `docs/link-outside` (refused at `root`), `docs/storage/file.md` (denylist), `docs/vendor/readme.md` (denylist), `docs/node_modules/x.md`, `docs/keys/server.key`, `docs/certs/a.pem`, `docs/auth.json`, `docs/notes.exe` (extension), `docs/guide.md` (allowed)
- [x] T007 [P] Write test `tests/Unit/Source/DocsPathPolicyConfigTest.php`: a config entry `sources.docs.paths` containing `.env` or `storage/` does not make the path readable; the denylist constant is not read from config (FR-010)
- [x] T008 [P] Write test `tests/Unit/Source/SensitiveNamesTest.php`: `matches('database.connections.mysql.password')` true; `DB_HOST` false; `STRIPE_SECRET` true; matching is case-insensitive; words `secret key token password dsn private credentials` each trigger (FR-008)
- [x] T009 Create `src/Source/SensitiveNames.php` with `matches(string ...$names): bool` (research R6)
- [x] T010 Create `src/Source/DocsPathPolicy.php` with constants `DENYLIST` (`.env*`, `*.key`, `*.pem`, `auth.json`, `storage/`, `vendor/`, `.git/`, `node_modules/`, dotfiles) and the five ordered steps from research R5; returns a decision with the failing step name
- [x] T011 Verify T006 and T007 pass; fix only the policy; do not weaken any case
- [x] T012 [P] Write test `tests/Unit/Source/SourceRequestResultTest.php`: `SourceResult::refused(step)` carries no path; `SourceResult::ok(data)` carries data and no refusal step
- [x] T013 Create `src/Source/Source.php` (interface `name()`, `read(SourceRequest $request): SourceResult`), `src/Source/SourceRequest.php`, `src/Source/SourceResult.php` (research R1)
- [x] T014 Add reason codes `source.missing`, `schema.missing`, `source.refused` to `src/Server/ReasonCode.php` (research R7)

**Checkpoint**: Foundation ready. The path policy is green on the adversarial set.

---

## Phase 3: User Story 1 - Ask how an endpoint is built (Priority: P1)

**Goal**: `routes.list` with method, uri, name, controller, rule names, middleware; exclusions; `<SECRET>` in paths.

**Independent Test**: Public route listed, Telescope route absent, long random segment replaced.

### Tests for User Story 1

- [x] T015 [P] [US1] Write test `tests/Unit/Source/RoutesExclusionTest.php`: each default pattern (`telescope/*`, `horizon/*`, `_ignition/*`, `_debugbar/*`, `pulse/*`, `sanctum/*`) is excluded; a config pattern adds an exclusion; config cannot remove a default (FR-002)
- [x] T016 [P] [US1] Write test `tests/Unit/Source/RoutesSecretSegmentTest.php`: a 32-character random segment becomes `<SECRET>`; a segment of 19 characters is kept (FR-003)
- [x] T017 [P] [US1] Write test `tests/Contract/RoutesListTest.php`: fixture route `POST api/orders` with a FormRequest returns method, uri, name, controller, rule names and middleware names (User Story 1, scenario 1)

### Implementation for User Story 1

- [x] T018 [US1] Create `src/Source/RoutesSource.php` reading `Route::getRoutes()`, applying exclusions (T015) and the secret segment rule (T016)
- [ ] T019 [US1] Read FormRequest rule keys by reflecting the class's `rules()` method without constructing it; empty list when unresolvable (research R2 verification point)
- [x] T020 [US1] Add `src/Server/SourceTools.php` entry for `routes.list` with its contract declared through spec 002 `ContextContract`
- [x] T021 [US1] Verify T015–T017 pass

---

## Phase 4: User Story 2 - Database structure without data (Priority: P1)

**Goal**: `schema.describe` with classes, secret columns removed and counted, relations, no row queries.

**Independent Test**: User model with email, password, name → `hidden_columns: 1`; query log empty.

### Tests for User Story 2

- [x] T022 [P] [US2] Write test `tests/Contract/SchemaSecretRemovedTest.php`: password column absent; `hidden_columns` is 1; email and name carry their classes (User Story 2, scenarios 1–2; SC-003)
- [x] T023 [P] [US2] Write test `tests/Contract/SchemaNoRowQueryTest.php`: after `schema.describe`, the query log contains no `select` on a model's table (FR-006, SC-002)
- [x] T024 [P] [US2] Write test `tests/Contract/SchemaRelationTest.php`: `Order::user()` appears as `{name, model, type}` and the related model's secret columns are hidden (User Story 2, scenario 3)
- [x] T025 [P] [US2] Write test `tests/Contract/SchemaMissingTableTest.php`: a model whose table does not exist returns `schema.missing` with no stack trace (FR-015)
- [x] T026 [P] [US2] Write test `tests/Unit/Source/SchemaPolicyTest.php`: a model not in `sources.eloquent.models` returns `policy.denied`

### Implementation for User Story 2

- [x] T027 [US2] Create `src/Source/SchemaSource.php` using `Schema::getColumns()`, `Schema::getIndexes()`, `getCasts()` and relation reflection (research R3)
- [x] T028 [US2] Apply the catalog classes from spec 002 and remove `secret` columns, counting them into `hidden_columns` (FR-005)
- [x] T029 [US2] Add the `schema.describe` entry to `src/Server/SourceTools.php`
- [x] T030 [US2] Verify T022–T026 pass

---

## Phase 5: User Story 3 - Configuration keys without values (Priority: P2)

**Goal**: `config.keys` shows keys and types; env names only when safe; values never.

**Independent Test**: `database.host` shows `env:DB_HOST`; `services.stripe.secret` shows `env:<hidden>`; no value anywhere.

### Tests for User Story 3

- [x] T031 [P] [US3] Write test `tests/Unit/Source/ConfigWalkTest.php`: keys are dotted; types are `string int float bool array null`; array items are never walked (edge case); no value object is built
- [x] T032 [P] [US3] Write test `tests/Unit/Source/ConfigEnvNameTest.php`: env name shown when neither key nor variable matches SensitiveNames; `env:<hidden>` otherwise (FR-008)
- [x] T033 [P] [US3] Write test `tests/Contract/ConfigNoValuesTest.php`: seeded value `FAKE_FIXTURE_ONLY` never appears in the response or the receipt (FR-007, SC-001)

### Implementation for User Story 3

- [x] T034 [US3] Create `src/Source/ConfigSource.php` walking `config()->all()` and storing only key and type
- [x] T035 [US3] Read the `env_map` from `config/context-gate.php` (`sources.config.env_map`) and apply SensitiveNames (research R4)
- [x] T036 [US3] Add the `config.keys` entry to `src/Server/SourceTools.php`
- [x] T037 [US3] Verify T031–T033 pass

---

## Phase 6: User Story 4 - Documentation from an approved list (Priority: P2)

**Goal**: `docs.read` through DocsPathPolicy, redaction and quarantine; truncation at line boundary.

**Independent Test**: fixture guide readable; symlink to `.env` refused with step `realpath`; `.env` refused with step `denylist`.

### Tests for User Story 4

- [x] T038 [P] [US4] Write test `tests/Contract/DocsReadAllowedTest.php`: `docs/guide.md` returned as `{untrusted: true, source, text}` (User Story 4, scenario 1)
- [x] T039 [P] [US4] Write test `tests/Contract/DocsRefusalTest.php`: the refusal response is `Request refused.` with no path; the receipt has `source.refused` and step name (User Story 4, scenarios 2–4; FR-017)
- [x] T040 [P] [US4] Write test `tests/Contract/DocsRedactionTest.php`: a seeded email inside the guide is replaced by its placeholder (User Story 4, scenario 5; SC-005)
- [x] T041 [P] [US4] Write test `tests/Unit/Source/DocsTruncationTest.php`: a file over the per-call limit is truncated at a line boundary with `truncated: true` (edge case)
- [x] T042 [P] [US4] Write test `tests/Unit/Source/DocsMissingFileTest.php`: a file deleted between listing and reading returns `source.missing` (edge case)

### Implementation for User Story 4

- [x] T043 [US4] Create `src/Source/DocsSource.php` calling `DocsPathPolicy` first, then reading the file with the size cap, then passing the text through the spec 002 normalizer, redactor, canary check and quarantine
- [x] T044 [US4] Add the `docs.read` entry to `src/Server/SourceTools.php`; symlink fixtures are verified on macOS and Linux
- [x] T045 [US4] Verify T038–T042 and the adversarial set T006 pass together

---

## Phase 7: Polish & Cross-Cutting Concerns

- [x] T046 [P] Write test `tests/Contract/ReadOnlyTest.php`: after every source call the fixture file modification times are unchanged and no model row was written (FR-016, research R8)
- [x] T047 [P] Write test `tests/EndToEnd/DocsRefusalStdioTest.php`: a refused docs call over stdio returns no path and stdout stays pure JSON-RPC
- [x] T048 Update `README.md` with the source list and the `env_map` and `docs.paths` config keys
- [ ] T049 [P] Update `CHANGELOG.md` `Unreleased` section
- [x] T050 Run `php scripts/check-file-length.php` and split any file over the limit
- [ ] T051 Run quickstart.md sections 1–4 on the fixture application and record results in `specs/003-laravel-sources/checklists/requirements.md`
- [x] T052 Run `composer test`, `composer stan` (level 8), `composer style`; all must exit 0

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 (path policy first) → US1 and US2 (P1) → US3 and US4 (P2) → Polish.
- Each story depends only on Phase 2 and on the spec 002 pipeline; stories are independent of each other.
- The path-policy test set (T006–T007) is a Phase 2 gate: no docs work starts before it is green.

## Parallel Examples

- Phase 2: T006, T007, T008 and T012 in parallel.
- US2: T022–T026 in parallel.
- US4: T038–T042 in parallel.

## Implementation Strategy

**MVP**: Phases 1–4 (routes and schema). Both are P1 and need no file reads; docs (US4) is the riskiest and
ships after its adversarial tests are green.

---

## Notes

- Constitution principle IX: write each test first, run it, see it fail, then implement.
- The denylist is code, not configuration. Any change to it needs a constitution review.
