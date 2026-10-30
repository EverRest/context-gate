# Tasks: MCP stdio Foundation

**Input**: Design documents from `specs/001-mcp-stdio-foundation/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R10), data-model.md, contracts/mcp-tools.md, quickstart.md

**Tests**: REQUIRED. Constitution principle IX (Test-First, NON-NEGOTIABLE): every test task comes before
its implementation task and must fail first. No test may touch the network.

**Organization**: Grouped by user story. US1 and US2 are the MVP (both P1). US5 is also P1 and ships with US1.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1…US6 from spec.md
- Paths are relative to the repository root

---

## Phase 1: Setup

**Purpose**: Package skeleton and gates. No story label.

- [ ] T001 Create `composer.json` with name `everrest/context-gate`, PHP `^8.2`, requires `laravel/framework` `^12.0||^13.0`, `laravel/mcp` `^1.1`, `laravel/sanctum` `^4.0`, PSR-4 `ContextGate\\` to `src/`, dev-requires `orchestra/testbench`, `phpunit/phpunit` `^11`, `larastan/larastan`, `laravel/pint`, scripts `test`, `stan`, `style`, `ci` (same names as watcher)
- [ ] T002 [P] Update `phpunit.xml.dist` so suites are `Unit`, `Contract`, `EndToEnd` and sources are `src/`; remove the `Perf` suite
- [ ] T003 [P] Update `phpstan.neon.dist` paths and keep level 8; remove the Redis env reference if present
- [ ] T004 [P] Update `.github/workflows/ci.yml`: remove the `scripts/coverage-floor.php` step and the Redis service; keep style, stan and test jobs
- [ ] T005 Create `tests/TestCase.php` extending `Orchestra\Testbench\TestCase` with the service provider loaded and `APP_ENV=testing`
- [ ] T006 Run `composer install` and confirm `composer test`, `composer stan`, `composer style` exit 0 on the empty suite

---

## Phase 2: Foundational

**Purpose**: Shared pieces every story uses. Blocks all user stories.

- [ ] T007 [P] Write test `tests/Unit/Server/ReasonCodeTest.php`: the enum has exactly `policy.hidden`, `tool.pin_mismatch`, `input.invalid`, `policy.denied`, `handler.failed`, and each maps to the decision in data-model.md
- [ ] T008 Create `src/Server/ReasonCode.php` as a backed string enum with those five cases and a `decision(): string` method
- [ ] T009 [P] Write test `tests/Unit/Server/ReceiptTest.php`: `Receipt::write()` writes one JSON line to the `context-gate` channel with keys `id, at, caller, tool, decision, reason, rules`; the test fails if any argument or result value appears in the line
- [ ] T010 Create `src/Server/Receipt.php` that builds the record and writes it through the `context-gate` log channel; returns the receipt id `r_` plus 8 hex characters
- [ ] T011 Create `config/context-gate.php` with `enabled => false` and `tools => []` and a `channel => 'context-gate'` entry
- [ ] T012 [P] Write test `tests/Unit/ContextGateServiceProviderTest.php`: the provider merges the config, registers the `context-gate` log channel to stderr only, and binds the caller id from the effective OS user of the process (`posix_getpwuid(posix_geteuid())['name']`), falling back to `unknown` when the POSIX functions are unavailable; the script owner is never used
- [ ] T013 Create `src/ContextGateServiceProvider.php` with `mergeConfigFrom`, the `context-gate` log channel (stderr driver), and the caller binding
- [ ] T014 [P] Write test `tests/Unit/Server/CallerTest.php`: `Caller::current()` returns the bound id and the allowed tool names from `config('context-gate.callers.<id>.tools')`
- [ ] T015 Create `src/Server/Caller.php`

**Checkpoint**: Foundation ready. Story phases can start.

---

## Phase 3: User Story 1 - Connect an IDE assistant (Priority: P1)

**Goal**: `initialize` works over stdio and `tools/list` is empty by default.

**Independent Test**: quickstart.md section 2 passes on a fresh install.

### Tests for User Story 1

- [ ] T016 [P] [US1] Write test `tests/EndToEnd/StdioHandshakeTest.php`: start `php artisan mcp:start context-gate` as a child process, send `initialize`, assert a valid result with the server name and version
- [ ] T017 [P] [US1] Write test `tests/Contract/ToolsListEmptyTest.php`: with the default config, `tools/list` returns `"tools": []`

### Implementation for User Story 1

- [ ] T018 [US1] Create `src/Server/GateServer.php` extending `Laravel\Mcp\Server` with `$name = 'context-gate'`, a version constant and an empty `$tools` list
- [ ] T019 [US1] Register the server in `src/ContextGateServiceProvider.php` with `Mcp::local('context-gate', GateServer::class)`
- [ ] T020 [US1] Verify T016 and T017 pass; fix only what they report

**Checkpoint**: US1 is independently testable.

---

## Phase 4: User Story 2 - Only allowed tools are visible and callable (Priority: P1)

**Goal**: A caller sees and calls only allowed tools; hidden and unknown tools answer identically.

**Independent Test**: Two tools configured, one allowed; list shows one; calling the hidden tool and an unknown name returns byte-identical responses (SC-002).

### Tests for User Story 2

- [ ] T021 [P] [US2] Write test `tests/Contract/HiddenToolTest.php`: the responses for a hidden tool and a non-existent tool are byte-identical JSON (contracts/mcp-tools.md, Hidden or unknown tool)
- [ ] T022 [P] [US2] Write test `tests/Unit/Server/ToolRegistryTest.php`: visibility is true only when the tool is enabled and allowed for the caller; a duplicate name throws on boot
- [ ] T023 [P] [US2] Write test `tests/Unit/Server/ReceiptHiddenTest.php`: a hidden call writes receipt reason `policy.hidden` and the response contains no reason text

### Implementation for User Story 2

- [ ] T024 [US2] Create `src/Server/Tool.php` as the base class with abstract `name()`, `description()`, `schema()`, `handle()`, and `shouldRegister()` that delegates to the registry
- [ ] T025 [US2] Create `src/Server/ToolRegistry.php` that loads tools from config, rejects duplicate names, and answers visibility per caller (R3)
- [ ] T026 [US2] Create `src/Server/GateCallTool.php` with the hidden path: return `JsonRpcException` `Tool [{name}] not found.` code -32602 and write the receipt
- [ ] T027 [US2] Register `GateCallTool` for `tools/call` in `GateServer` with `addMethod('tools/call', GateCallTool::class)`
- [ ] T028 [US2] Verify T021–T023 pass

**Checkpoint**: US1 and US2 together are the MVP.

---

## Phase 5: User Story 3 - Policy and argument checks per call (Priority: P2)

**Goal**: Unknown argument keys are rejected; policy refusals return `policy.denied` with no rule details.

**Independent Test**: One tool with a schema and a policy that refuses one value; valid call runs, unknown key gives `input.invalid`, refused value gives `policy.denied`.

### Tests for User Story 3

- [ ] T029 [P] [US3] Write test `tests/Unit/Server/ArgumentValidatorTest.php`: a key not in `schema()` fails with `input.invalid` naming the key; a value violating the schema fails; valid arguments pass (R5)
- [ ] T030 [P] [US3] Write test `tests/Unit/Policy/ToolPolicyTest.php`: `ToolPolicy::allows()` calls the configured Gate ability and returns its result; a missing ability denies
- [ ] T031 [P] [US3] Write test `tests/Contract/PolicyDeniedTest.php`: refusal returns `isError: true` with text `Request refused.` and the receipt has `policy.denied`

### Implementation for User Story 3

- [ ] T032 [US3] Create `src/Server/ArgumentValidator.php` using `illuminate/json-schema` from `schema()` and the Laravel Validator; reject unknown keys with `input.invalid`
- [ ] T033 [US3] Create `src/Policy/ToolPolicy.php` that resolves the configured Gate ability for the caller
- [ ] T034 [US3] Extend `GateCallTool` to run validation before the handler and policy after validation, in that order (FR-007, FR-008)
- [ ] T035 [US3] Verify T029–T031 pass

---

## Phase 6: User Story 4 - Changed tools are disabled until reviewed (Priority: P2)

**Goal**: A description change hides the tool and records `tool.pin_mismatch`.

**Independent Test**: Approve a tool, change its description, restart; tool hidden; receipt reason `tool.pin_mismatch`.

### Tests for User Story 4

- [ ] T036 [P] [US4] Write test `tests/Unit/Server/FingerprintTest.php`: the fingerprint is a stable sha256 of the description; a changed description produces a different value
- [ ] T037 [P] [US4] Write test `tests/Contract/PinMismatchTest.php`: a tool with a stale fingerprint is hidden and its receipt has `tool.pin_mismatch`

### Implementation for User Story 4

- [ ] T038 [US4] Create `src/Server/Fingerprint.php` with `of(Tool $tool): string`
- [ ] T039 [US4] Add the fingerprint check to `ToolRegistry` visibility (R9)
- [ ] T040 [US4] Create `src/Console/FingerprintCommand.php` for `gate:fingerprint {tool}` that prints the value to paste into config
- [ ] T041 [US4] Register `gate:fingerprint` in `src/ContextGateServiceProvider.php`
- [ ] T042 [US4] Verify T036–T037 pass

---

## Phase 7: User Story 5 - No stdio in production (Priority: P1)

**Goal**: stdio refuses to start with `APP_ENV=production`; stdout carries only protocol.

**Independent Test**: quickstart.md section 4 passes; process test shows only JSON lines on stdout.

### Tests for User Story 5

- [ ] T043 [P] [US5] Write test `tests/EndToEnd/ProductionRefusalTest.php`: with `APP_ENV=production` the command exits 1, stdout is empty, stderr names the reason
- [ ] T044 [P] [US5] Write test `tests/EndToEnd/StdoutPurityTest.php`: during a full session every stdout line parses as JSON-RPC (FR-011)

### Implementation for User Story 5

- [ ] T045 [US5] Add the production guard to `GateServer::boot()` that writes the reason to stderr and exits 1 (R8)
- [ ] T046 [US5] Verify T043–T044 pass

---

## Phase 8: User Story 6 - Install from a closed configuration (Priority: P3)

**Goal**: `gate:install` publishes a configuration with everything disabled and does not overwrite.

**Independent Test**: quickstart.md section 1 on a fresh Testbench application.

### Tests for User Story 6

- [ ] T047 [P] [US6] Write test `tests/Unit/Console/InstallCommandTest.php`: running install publishes `config/context-gate.php` with `enabled => false` and no tools
- [ ] T048 [P] [US6] Write test `tests/Unit/Console/InstallOverwriteTest.php`: a second run without `--force` leaves an existing file unchanged and exits 1

### Implementation for User Story 6

- [ ] T049 [US6] Create `src/Console/InstallCommand.php` for `gate:install` with the overwrite guard (R10)
- [ ] T050 [US6] Register `gate:install` in `src/ContextGateServiceProvider.php`
- [ ] T051 [US6] Verify T047–T048 pass

---

## Phase 9: Polish & Cross-Cutting Concerns

- [ ] T052 [P] Write test `tests/Contract/HandlerFailureTest.php`: a throwing handler returns `Request failed. Reference: r_...`, the exception text is absent from the response, and the receipt has `handler.failed` (FR-015)
- [ ] T053 [P] Write test `tests/Contract/ReceiptPerCallTest.php`: every call in the suite writes exactly one receipt (SC-006)
- [ ] T054 Implement the handler failure path in `src/Server/GateCallTool.php` to make T052 pass
- [ ] T055 Create `tests/Fixtures/EchoTool.php` (allowed) and `tests/Fixtures/HiddenTool.php` (not allowed) for the suites
- [ ] T056 [P] Update `README.md` with install, enable and start steps from quickstart.md
- [ ] T057 [P] Update `CHANGELOG.md` with an `Unreleased` entry for 0.1.0-dev
- [ ] T058 Run `php scripts/check-file-length.php` and split any file over the limit
- [ ] T059 Run quickstart.md sections 1–5 on a fresh application and record results in `specs/001-mcp-stdio-foundation/checklists/requirements.md`
- [ ] T060 Run `composer test`, `composer stan`, `composer style`; all must exit 0

---

## Dependencies & Execution Order

- Setup (Phase 1) → Foundational (Phase 2) → story phases.
- US1 and US2 are P1 and form the MVP. US5 is P1 and can start after Foundational in parallel with US1.
- US3 depends on US2 (it extends `GateCallTool`). US4 depends on US2 (it extends `ToolRegistry`).
- US6 is independent after Foundational.
- Polish depends on all stories.

## Parallel Examples

- Phase 2: T007, T009, T012 and T014 can run in parallel (separate test files).
- US2: T021, T022 and T023 can run in parallel.
- US5: T043 and T044 can run in parallel.

## Implementation Strategy

**MVP**: Phases 1–4 (US1 and US2). Delivers a server that lists nothing by default and hides every tool
a caller may not use.

**Incremental**: then US5 (production refusal, a must before any real use), then US3, US4, US6, and
finally Polish.

---

## Notes

- Constitution principle IX: write each test first, run it, see it fail, then implement.
- Every task that writes code must leave the three gates green before the next story starts.
- Do not add a runtime dependency that is not in plan.md.
