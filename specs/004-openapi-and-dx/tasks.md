# Tasks: OpenAPI Source and Developer Tooling

**Input**: Design documents from `specs/004-openapi-and-dx/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R9), data-model.md, contracts/openapi-source.md, quickstart.md.
Specs 001–003 must be complete.

**Tests**: REQUIRED (constitution IX). Tests precede implementation. The network security tests are written
first (Phase 2) because URL fetching is a server-side request path.

**Organization**: Grouped by user story. US1 and US2 are P1; US3, US4 and US5 are P2.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup

- [x] T001 Add `"symfony/yaml": "^7.0"` to `require` in `composer.json` (plan Complexity Tracking)
- [x] T002 [P] Create `tests/Fixtures/openapi/valid-3.1.json` with two endpoints, one request body and one `example` containing the seeded email `canary-fixture@example.invalid`
- [x] T003 [P] Create `tests/Fixtures/openapi/swagger-2.json` (version `2.0`) and `tests/Fixtures/openapi/oversized.json` padded to 2 MB + 1 byte
- [x] T004 [P] Create `tests/Fixtures/openapi/external-ref.yaml` with a `$ref` to `https://example.com/other.yaml`
- [x] T005 [P] Create `tests/Fixtures/openapi/valid-3.0.yaml` with the same endpoints as T002 in YAML

---

## Phase 2: Foundational

**Purpose**: Network security first. Written as failing tests before any implementation.

- [x] T006 [P] Write test `tests/Unit/Fetch/AddressRangesTest.php` with a table: IPv4 `10.0.0.5`, `172.16.0.1`, `192.168.1.1`, `127.0.0.1`, `169.254.169.254`, `0.0.0.0`, `100.64.0.1`, `224.0.0.1` refused; `8.8.8.8` accepted; IPv6 `::1`, `fc00::1`, `fe80::1`, `::` refused; `::ffff:10.0.0.5` refused as IPv4 mapped; `2606:4700::1111` accepted
- [x] T007 [P] Write test `tests/Unit/Fetch/UrlGuardTest.php`: non-https refused; userinfo (`https://user@host`) refused; fragment refused; host not on allowlist refused; empty allowlist refuses every URL; an allowlisted host with a refused resolved address refuses the whole request (FR-006)
- [x] T008 [P] Write test `tests/Unit/Fetch/RebindingTest.php`: an injected Resolver returns `203.0.113.10` on the first call and `127.0.0.1` on the second; the fake transport records the connect address; the request connects to `203.0.113.10` and the second lookup is never made (FR-007, research R2 rebinding test)
- [x] T009 [P] Write test `tests/Unit/Fetch/RedirectRefusalTest.php`: a 302 from the fake transport returns `source.redirect`; no second request is sent (FR-008)
- [x] T010 [P] Write test `tests/Unit/Fetch/SizeAndTimeoutTest.php`: a response over 2 MB stops reading and returns `source.too_large` (FR-010); a transport that exceeds 5 seconds returns `source.timeout` (FR-009)
- [ ] T011 [P] Write test `tests/Unit/Console/NoValueOutputTest.php`: each of the four commands runs against a fixture with a seeded value; the value appears in neither stdout nor stderr (FR-018, research R9)
- [x] T012 Create `src/Source/Fetch/AddressRanges.php` with the IPv4 and IPv6 refusal list (research R2 step 4) until T006 passes
- [x] T013 Create `src/Source/Fetch/Resolver.php` as an interface with a production implementation using `dns_get_record` for A and AAAA, and a test double injected through the container
- [x] T014 Create `src/Source/Fetch/UrlGuard.php` implementing R2 steps 1–4 until T007 passes
- [x] T015 Create `src/Source/Fetch/PinnedClient.php` implementing R2 steps 5–8: pinned connect address, redirects disabled, 5-second timeout, 2 MB read cap, JSON or YAML content types only, until T008–T010 pass
- [x] T016 Create `src/Source/OpenApiParser.php` with the version check (`3.` prefix; Swagger 2.0 refused with `openapi.version`) and the size check (FR-010)
- [x] T017 Confirm T006–T010 are green together; fix only the fetch code, never the tests

**Checkpoint**: Network path is green. No URL reaches the parser without passing UrlGuard and PinnedClient.

---

## Phase 3: User Story 1 - Ask about an API from its contract (Priority: P1)

**Goal**: `openapi.endpoints` lists endpoints with schemas through a contract; examples dropped by default.

**Independent Test**: Local 3.1 file; two endpoints with schemas; seeded email in an example absent.

### Tests for User Story 1

- [x] T018 [P] [US1] Write test `tests/Unit/Source/ExamplePolicyTest.php`: `example`, `examples`, `x-example` and string `default` are removed in `drop` mode (FR-003); the schema structure is unchanged (FR-004)
- [x] T019 [P] [US1] Write test `tests/Contract/OpenApiEndpointsTest.php`: the local fixture returns method, path, parameters, request schema, response schemas and security names for each endpoint (FR-002, User Story 1 scenario 1)
- [x] T020 [P] [US1] Write test `tests/Contract/OpenApiExampleAbsentTest.php`: the seeded email from T002 appears nowhere in the response (User Story 1 scenario 2; SC-001)
- [x] T021 [P] [US1] Write test `tests/Unit/Source/ExternalRefTest.php`: the `$ref` in T004 is listed as `ref.external` with no content; the external URL is not requested (FR-011)

### Implementation for User Story 1

- [x] T022 [US1] Create `src/Source/ExamplePolicy.php` with the `drop` behaviour (research R3)
- [x] T023 [US1] Create `src/Source/OpenApiSource.php` implementing `Source` (spec 003) for local paths: uses `DocsPathPolicy` for the path, `OpenApiParser`, `ExamplePolicy`
- [x] T024 [US1] Add endpoint extraction to `OpenApiParser` (data-model OpenApiEndpoint) and the external reference rule (FR-011)
- [x] T025 [US1] Add the `openapi.endpoints` entry to `src/Server/SourceTools.php` with its contract
- [x] T026 [US1] Verify T018–T021 pass

---

## Phase 4: User Story 2 - Refuse dangerous network targets (Priority: P1)

**Goal**: URL path of `openapi.endpoints` uses UrlGuard and PinnedClient end to end.

**Independent Test**: Empty allowlist refuses; allowlisted host resolving to `169.254.169.254` refuses; redirect refused; slow server times out.

### Tests for User Story 2

- [ ] T027 [P] [US2] Write test `tests/Contract/OpenApiUrlRefusalTest.php`: each refusal case from User Story 2 returns the expected reason code and `Request refused.` with no address in the response (SC-002)
- [ ] T028 [P] [US2] Write test `tests/Contract/OpenApiUrlSuccessTest.php`: an allowlisted host resolving to a public address and returning the fixture is accepted, and the receipt has `fetch: {host, resolved_count, pinned: true}` (contract receipt additions)

### Implementation for User Story 2

- [ ] T029 [US2] Extend `OpenApiSource` with the URL branch: `UrlGuard` → `PinnedClient` → `OpenApiParser`
- [ ] T030 [US2] Add the `url` argument to `openapi.endpoints` in `src/Server/SourceTools.php` with exactly-one-of validation (path or url)
- [ ] T031 [US2] Verify T027–T028 pass

---

## Phase 5: User Story 3 - Draft the classification catalog (Priority: P2)

**Goal**: `gate:classify` writes a draft; suggestions marked; unknowns are `secret // needs decision`; no overwrite without `--force`.

**Independent Test**: Schema with `email`, `password`, `total`; draft matches; second run refused.

### Tests for User Story 3

- [ ] T032 [P] [US3] Write test `tests/Unit/Classification/DraftWriterTest.php`: `email` → `personal // suggested`; `password` → `secret // suggested`; `total` → `secret // needs decision` (User Story 3 scenarios 1–2)
- [ ] T033 [P] [US3] Write test `tests/Unit/Console/ClassifyOverwriteTest.php`: an existing `app/Gate/classification.php` is unchanged without `--force` and the command exits 1 (FR-014)
- [ ] T034 [P] [US3] Write test `tests/Unit/Console/ClassifyEmptyDatabaseTest.php`: no tables produce an empty catalog with a comment explaining why (edge case)
- [ ] T035 [P] [US3] Write test `tests/Contract/ClassifyDraftInertTest.php`: a draft that still contains markers is rejected by `gate:check` as a catalog (User Story 3 scenario 4)

### Implementation for User Story 3

- [ ] T036 [US3] Create `src/Classification/DraftWriter.php` with the suggestion list from research R6
- [ ] T037 [US3] Create `src/Console/ClassifyCommand.php` for `gate:classify [--force]` using SchemaSource (spec 003)
- [ ] T038 [US3] Register `gate:classify` in `src/ContextGateServiceProvider.php`
- [ ] T039 [US3] Verify T032–T035 pass

---

## Phase 6: User Story 4 - Preview what the assistant receives (Priority: P2)

**Goal**: `gate:preview` prints the exact pipeline output and dropped fields; writes a receipt; sends nothing.

**Independent Test**: Golden output equals the tool response byte for byte; dropped field listed with its reason.

### Tests for User Story 4

- [ ] T040 [P] [US4] Write test `tests/Contract/PreviewByteEqualTest.php`: the preview output equals the tool response from the same arguments byte for byte (SC-004)
- [ ] T041 [P] [US4] Write test `tests/Unit/Console/PreviewDroppedTest.php`: the `Dropped:` section lists each dropped field with its reason code (User Story 4 scenario 2)
- [ ] T042 [P] [US4] Write test `tests/Unit/Console/PreviewReceiptTest.php`: a preview run writes a receipt with `source: preview` and no value (User Story 4 scenario 3; FR-016)

### Implementation for User Story 4

- [ ] T043 [US4] Create `src/Console/PreviewCommand.php` for `gate:preview {tool} [--args=]` that runs the spec 002 pipeline with a no-delivery flag
- [ ] T044 [US4] Register `gate:preview` in `src/ContextGateServiceProvider.php`
- [ ] T045 [US4] Verify T040–T042 pass

---

## Phase 7: User Story 5 - Plant canary values (Priority: P2)

**Goal**: `gate:canary` prints values and placements, writes nothing.

**Independent Test**: File hashes before and after are identical; values match the shapes.

### Tests for User Story 5

- [ ] T046 [P] [US5] Write test `tests/Unit/Console/CanaryShapeTest.php`: email shape `canary-<8 hex>@example.invalid`; token shape `CANARY_<24 hex>`; values differ between runs
- [ ] T047 [P] [US5] Write test `tests/Contract/CanaryNoWriteTest.php`: project file hashes are identical before and after `gate:canary` (User Story 5 scenario 2; SC-005)

### Implementation for User Story 5

- [ ] T048 [US5] Create `src/Console/CanaryCommand.php` generating values with `random_bytes` and printing placements
- [ ] T049 [US5] Register `gate:canary` in `src/ContextGateServiceProvider.php`
- [ ] T050 [US5] Verify T046–T047 pass

---

## Phase 8: Polish & Cross-Cutting Concerns

- [ ] T051 [P] Write test `tests/Unit/Console/ScanInertTest.php`: `gate:scan` enables no tool and changes no configuration file (FR-012, SC-003)
- [ ] T052 Create `src/Console/ScanCommand.php` for `gate:scan [--json]` reading routes and models; writes `storage/context-gate/scan.json` only with `--json`
- [ ] T053 Register `gate:scan` in `src/ContextGateServiceProvider.php`
- [ ] T054 Verify T051 and T011 (no value output for all four commands) pass
- [ ] T055 [P] Update `README.md` with the URL allowlist config, the examples mode, and the four commands
- [ ] T056 [P] Update `CHANGELOG.md` `Unreleased` section
- [ ] T057 Run `php scripts/check-file-length.php` and split any file over the limit
- [ ] T058 Run quickstart.md sections 1–5 on the fixture application; record results in `specs/004-openapi-and-dx/checklists/requirements.md`
- [ ] T059 Run `composer test`, `composer stan` (level 8), `composer style`; all must exit 0

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 (network security first) → US1 and US2 (P1) → US3, US4, US5 (P2, independent of each other) → Polish.
- US2 extends US1's source. US3 depends on SchemaSource from spec 003. US4 depends on the spec 002 pipeline.
- Phase 2 is a hard gate: no URL code path ships before T017 passes.

## Parallel Examples

- Phase 2: T006–T011 in parallel (separate test files).
- US3, US4 and US5 can run in parallel after Phase 2.

## Implementation Strategy

**MVP**: Phases 1–4 (local and URL OpenAPI with the full security path). This is the only feature in this
spec that makes a network request, so it is the first thing reviewed.

**Incremental**: then US3 (catalog draft), US4 (preview), US5 (canary), and Polish.

---

## Notes

- Constitution principle IX: write each test first, run it, see it fail, then implement.
- Refusal messages never contain an address, host list or document content (contract).
- No command writes outside its requested file (plan Summary).
