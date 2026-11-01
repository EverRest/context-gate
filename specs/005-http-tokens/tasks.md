# Tasks: HTTP Access and Tokens

**Input**: Design documents from `specs/005-http-tokens/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R11), data-model.md, contracts/http-and-tokens.md,
quickstart.md. Specs 001–004 must be complete.

**Tests**: REQUIRED (constitution IX). Tests precede implementation. The 401/403/200 status matrix is written
first (Phase 2), because it is the access policy in executable form.

**Organization**: Grouped by user story. US1, US2 and US3 are P1; US4 and US5 are P2.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup

- [x] T001 Create migration `database/migrations/2026_10_30_000000_create_gate_token_issuers_table.php` with table `gate_token_issuers`: `id` bigint, `token_id` unsigned bigint nullable foreign key to `gate_access_tokens.id` with set null on delete (the issuer row is kept when its token is deleted), `issuer` string(120), `created_at` timestamp (data-model TokenIssuer)
- [x] T002 [P] Add the `http` section to `config/context-gate.php` with `'path' => 'mcp/context-gate'` and `'origins' => []` (contract Config)
- [x] T003 [P] Add a `tests/Fixtures/TokenFactory.php` helper that creates a Sanctum token with given abilities and expiry in the in-memory test database
- [ ] T004 Confirm `laravel/sanctum` ^4 is in `composer.json` `require` and `composer install` succeeds

---

## Phase 2: Foundational

**Purpose**: Status matrix and middleware tests first; then the shared rules.

- [x] T005 Write test `tests/Contract/HttpStatusMatrixTest.php` as a table: no token → 401 `token.missing`; unknown token → 401 `token.invalid`; expired token → 401 `token.invalid`; revoked token → 401 `token.invalid`; token without `gate:read` → 403 `token.no_read`; token with no ceiling → 401 `token.no_ceiling`; token with two ceilings → 401 `token.no_ceiling`; foreign Origin → 403 `origin.denied`; valid token and allowlisted Origin → 200; valid token and no Origin → 200 (contract HTTP route, research R7)
- [x] T006 [P] Write test `tests/Unit/Http/GateTokenAuthTest.php`: exactly one `gate:ceiling:*` ability sets the ceiling on the request; zero or two fail with `token.no_ceiling`; the ceiling is taken from the token only (FR-012)
- [x] T007 [P] Write test `tests/Unit/Http/GateOriginCheckTest.php`: empty allowlist refuses any Origin header and accepts no Origin header; exact match is accepted; differing port or scheme refused; `Origin: null` refused (FR-014)
- [x] T008 [P] Write test `tests/Unit/Token/AbilityTest.php`: `gate:read`, `gate:ceiling:public`, `gate:ceiling:internal`, `gate:ceiling:personal` accepted; `gate:ceiling:secret`, `gate:write`, `*` refused (FR-007, R3)
- [x] T009 [P] Write test `tests/Unit/Token/CeilingTest.php`: `Ceiling::from('secret')` is not a valid case for tokens; `public`, `internal`, `personal` are valid (R3)
- [x] T010 Create `src/Token/Ability.php` with the allowlist and `Ability::isPackageAbility(string $name): bool`
- [x] T011 Create `src/Token/Ceiling.php` as a restricted list of `public`, `internal`, `personal` for tokens, reusing the spec 002 class order
- [x] T012 Create `src/Http/GateOriginCheck.php` (R5) until T007 passes
- [x] T013 Create `src/Http/GateTokenAuth.php` (R8) until T006 passes; it stores the ceiling on the request for the spec 002 pipeline
- [x] T014 Add `token.missing`, `token.invalid`, `token.no_read`, `token.no_ceiling`, `origin.denied` to `src/Server/ReasonCode.php` (data-model reason codes)
- [x] T015 Create `src/Token/TokenIssuer.php` that writes one `gate_token_issuers` row in the same transaction as the Sanctum token (R6)

**Checkpoint**: Middleware and ability rules are green. The status matrix (T005) is still red until the route exists (Phase 3).

---

## Phase 3: User Story 1 - Connect remotely with a personal token (Priority: P1)

**Goal**: Streamable HTTP route with the full middleware chain; same tools as stdio.

**Independent Test**: Valid token, initialize, list, call; receipt names the holder.

### Tests for User Story 1

- [x] T016 [P] [US1] Write test `tests/EndToEnd/HttpInitializeTest.php`: POST initialize with a valid token returns the server name and version (User Story 1 scenario 1)
- [x] T017 [P] [US1] Write test `tests/Contract/HttpToolsListTest.php`: the tool list over HTTP equals the list over stdio for the same token (FR-001)
- [x] T018 [P] [US1] Write test `tests/Contract/HttpReceiptHolderTest.php`: a tool call writes a receipt whose caller is the token holder name and whose content has no value (User Story 1 scenario 3)
- [x] T019 [P] [US1] Write test `tests/EndToEnd/HttpProductionTest.php`: with `APP_ENV=production` a valid token is served over HTTP while stdio is refused (User Story 1 scenario 4; FR-013)
- [x] T020 [P] [US1] Write test `tests/Unit/Http/ClientInfoCeilingTest.php`: a `clientInfo` that claims a higher ceiling does not change the tool list (User Story 1; FR-012, SC-005)

### Implementation for User Story 1

- [x] T021 [US1] Create `src/Http/HttpRoute.php` that calls `Mcp::web(config('context-gate.http.path'), GateServer::class)` and appends `auth:context-gate`, `GateOriginCheck`, `GateTokenAuth`, `abilities:gate:read` (contract HTTP route)
- [x] T022 [US1] Register `HttpRoute` in `src/ContextGateServiceProvider.php` boot method
- [x] T023 [US1] Make `GateServer` read the request ceiling set by `GateTokenAuth` for the spec 002 pipeline when running over HTTP; stdio keeps its config ceiling
- [x] T024 [US1] Verify T016–T020 pass

---

## Phase 4: User Story 2 - Only authorized tokens get in (Priority: P1)

**Goal**: Every refusal returns the right status and no detail.

**Independent Test**: Each refused case from User Story 2 returns its status.

### Tests for User Story 2

- [x] T025 [P] [US2] Write test `tests/Contract/TokenRefusalTest.php`: no token, expired, revoked, no ceiling each return 401 with `WWW-Authenticate`; no `gate:read` returns 403 `insufficient_scope` (User Story 2 scenarios 1–5; SC-001)
- [x] T026 [P] [US2] Write test `tests/Contract/RefusalBodyTest.php`: 401 and 403 bodies contain no reason text and no token value (FR-016)
- [x] T027 [P] [US2] Write test `tests/Contract/RevokedImmediateTest.php`: a token revoked between two requests is refused at the second (User Story 2 scenario 3; SC-004)

### Implementation for User Story 2

- [x] T028 [US2] Ensure the `abilities:gate:read` failure path returns the same body shape as the missing-token path (contract Responses table)
- [x] T029 [US2] Verify T025–T027 pass together with the matrix in T005

---

## Phase 5: User Story 3 - Issue tokens with an expiry and a ceiling (Priority: P1)

**Goal**: `gate:token` issues a token only with `--expires` ≤ 90 days, a ceiling, package abilities; shows the value once; writes a receipt.

**Independent Test**: Missing expiry refused; 120 days refused; 30 days with `internal` issued and receipt written.

### Tests for User Story 3

- [x] T030 [P] [US3] Write test `tests/Unit/Console/TokenExpiryTest.php`: missing `--expires` exits 1 and issues nothing (User Story 3 scenario 1; FR-006)
- [x] T031 [P] [US3] Write test `tests/Unit/Console/TokenMaxExpiryTest.php`: `--expires=120d` exits 1 naming the 90-day maximum; `--expires=90d` is accepted (SC-002)
- [x] T032 [P] [US3] Write test `tests/Unit/Console/TokenAbilityTest.php`: `--ability=gate:write` exits 1 (FR-007, User Story 3 scenario 4)
- [x] T033 [P] [US3] Write test `tests/Unit/Console/TokenOneTimeDisplayTest.php`: the token value is printed once on issuance and the output says so; a second run of `--list` does not print it (FR-008)
- [x] T034 [P] [US3] Write test `tests/Unit/Token/IssuanceReceiptTest.php`: a `token.issued` receipt contains issuer, holder, ceiling, expires_at, token_id and no token value (FR-009, SC-003)
- [x] T035 [P] [US3] Write test `tests/Unit/Token/TokenHashStorageTest.php`: the `gate_access_tokens` row does not contain the plain token (research R4)

### Implementation for User Story 3

- [x] T036 [US3] Create `src/Console/TokenCommand.php` for issuance with options `--holder`, `--issuer`, `--ceiling`, `--expires`, `--ability` (repeatable), validating each rule above
- [x] T037 [US3] Issue through Sanctum `createToken()` with `expires_at` set from `--expires` (UTC) and the abilities, then call `TokenIssuer` (R2, R6)
- [x] T038 [US3] Write the `token.issued` receipt through the `context-gate` channel (R11)
- [x] T039 [US3] Register `gate:token` in `src/ContextGateServiceProvider.php`
- [x] T040 [US3] Verify T030–T035 pass

---

## Phase 6: User Story 4 - See and revoke active tokens (Priority: P2)

**Goal**: `gate:token --list` shows metadata; `--revoke <id>` deletes and records.

**Independent Test**: Two issued; list shows two; revoke one; list shows one; revoked token refused.

### Tests for User Story 4

- [x] T041 [P] [US4] Write test `tests/Unit/Console/TokenListTest.php`: list shows id, holder, issuer, ceiling, expires_at, issued_at and no token value (FR-010, SC-003)
- [x] T042 [P] [US4] Write test `tests/Unit/Console/TokenRevokeTest.php`: revoke deletes the Sanctum token and the issuer row, writes `token.revoked`, and an unknown id exits 1 with no change (FR-011, contract)
- [x] T043 [P] [US4] Write test `tests/Unit/Console/TokenRevokeTwiceTest.php`: revoking an already revoked id reports it and exits 1 (edge case)

### Implementation for User Story 4

- [x] T044 [US4] Add `--list` and `--revoke=<id>` to `src/Console/TokenCommand.php`
- [x] T045 [US4] Verify T041–T043 pass

---

## Phase 7: User Story 5 - Refuse foreign origins (Priority: P2)

**Goal**: Foreign Origin refused; no Origin accepted with valid token; allowlisted Origin accepted.

**Independent Test**: Foreign origin with valid token → 403; no origin with valid token → 200.

### Tests for User Story 5

- [x] T046 [P] [US5] Write test `tests/Contract/OriginScenarioTest.php`: the three scenarios from User Story 5 return 403, 200 and 200 (SC-006)
- [x] T047 [P] [US5] Write test `tests/Contract/OriginBeforeTokenTest.php`: a foreign Origin with no token returns 403, not 401, so that the origin decision does not depend on a token (order of middleware)

### Implementation for User Story 5

- [x] T048 [US5] Confirm middleware order in `src/Http/HttpRoute.php`: `GateOriginCheck` runs before `auth:context-gate`
- [x] T049 [US5] Verify T046–T047 pass

---

## Phase 8: Polish & Cross-Cutting Concerns

- [x] T050 [P] Write test `tests/Contract/TokenEdgeCasesTest.php`: a token that expires during an open session is refused at the next request; clock is compared in UTC (edge cases, FR-015)
- [x] T051 Update `README.md` with the HTTP route, the token command, and the Origin allowlist
- [x] T052 [P] Update `CHANGELOG.md` `Unreleased` section
- [x] T053 Run `php scripts/check-file-length.php` and split any file over the limit
- [ ] T054 Run quickstart.md sections 1–6 on the fixture application; record results in `specs/005-http-tokens/checklists/requirements.md`
- [x] T055 Run `composer test`, `composer stan` (level 8), `composer style`; all must exit 0

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 (matrix and middleware) → US1 (route) → US2 → US3 (issuance). US3 can start after Phase 2,
  because the middleware tests use fixtures; but the status matrix in Phase 2 needs US1's route to go green.
- US4 depends on US3 (tokens to list and revoke). US5 depends on US1 (route exists).
- Polish depends on all stories.

## Parallel Examples

- Phase 2: T006, T007, T008, T009 in parallel.
- US1: T016–T020 in parallel.
- US3: T030–T035 in parallel.

## Implementation Strategy

**MVP**: Phases 1–3 (route with full middleware chain and initialize over HTTP). This is the minimum for on-call
access; issuance (US3) must ship with it, because without it there is no token to use.

**Incremental**: then US2 refusals, US3 issuance, US4 list and revoke, US5 origin tests, Polish.

---

## Notes

- Constitution principle IX: write each test first, run it, see it fail, then implement.
- Refusal bodies never name the failed check (FR-016).
- `secret` is not a ceiling (research R3); tests T008–T009 guard it.
