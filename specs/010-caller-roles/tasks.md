# Tasks: Caller Roles

**Input**: Design documents from `specs/010-caller-roles/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R8), data-model.md, contracts/roles-and-token-command.md.
Spec 009 complete (at least its Phase 2 and US4: `ActiveSettings` getters and `RuleHashes`). Owner confirmation of
research R8 (removing `callers` after the dated 0.1.0 entry) before T002.

**Tests**: REQUIRED (constitution IX). Every implementation task is preceded by a failing test.

**Organization**: by user story. US1, US2, US3 are P1; US4, US5, US6 are P2.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Foundational — roles in settings, role on the token

**Checkpoint**: roles validate and are readable; tokens can carry a role ability; nothing uses them yet.

- [ ] T001 [P] Write test `tests/Unit/Settings/RoleSettingsTest.php`: a valid role passes; each rule of research R1 fails with its key (`roles.a b` name, unknown tool in `roles.x.tools`, ceiling `secret`, limit above the strict profile value under `APP_ENV=production`, limit equal to it passes, non-empty `scope`, unknown key under a role, `stdio_roles.dev` naming an undefined role, any `callers` key with the pointer message); an empty tools list passes
- [ ] T002 Create `src/Settings/Role.php`; edit `src/Settings/SettingsSchema.php` and `src/Settings/SettingsValidator.php` (replace the callers cross-check with the role rules)
- [ ] T003 [P] Write test additions in `tests/Unit/Settings/ActiveSettingsTypedTest.php`: `roles()` returns `Role` objects with effective limits merged over profile values; `stdioRoles()` returns the map; both throw `InvalidSettings` on an invalid document
- [ ] T004 Edit `src/Settings/ActiveSettings.php`: add `roles()`, `stdioRoles()`; remove `callers()`
- [ ] T005 [P] Write test `tests/Unit/Token/RoleAbilityTest.php`: `Ability::isPackageAbility('gate:role:support')` true; `gate:role:` with an empty, spaced, dotted or 65-char name false; `TokenIssuer::issue` with role `support` stores `gate:read`, one ceiling ability and `gate:role:support`
- [ ] T006 Edit `src/Token/Ability.php` (`ROLE_PREFIX`, name pattern) and `src/Token/TokenIssuer.php` (`string $role` parameter)
- [ ] T007 Edit `config/context-gate.php`: remove `callers`, add `roles => []` and `stdio_roles => []` with comments; update `tests/Unit/Settings/ConfigDefaultsTest.php` first

---

## Phase 2: User Story 1 - Issue a token that works at once (P1)

**Independent Test**: define a role, issue a token with it, list tools over HTTP: the role's tools appear.

- [ ] T008 [P] [US1] Write test `tests/Contract/Roles/TokenCommandRoleTest.php`: missing `--role` and undefined role exit 1 and issue nothing; no `--ceiling` stores the role ceiling; narrower `--ceiling` is stored as given; wider exits 1 and issues nothing; output has `Role: support` and no `callers` text; `token.issued` receipt has `role`; `--list` and `--list --json` show the role, `none` for a token without a role ability (contracts table)
- [ ] T009 [US1] Edit `src/Console/TokenCommand.php` per contracts/roles-and-token-command.md
- [ ] T010 [P] [US1] Write test `tests/Unit/Http/GateTokenAuthRoleTest.php`: a token with one role ability sets `context_gate.role`; with none it authenticates and sets null; with two role abilities it gets `403` and a `token.ability` receipt
- [ ] T011 [US1] Edit `src/Http/GateTokenAuth.php` (`ROLE_ATTRIBUTE`) and `src/Server/GateServer.php::bindTokenContext()` (bind `context-gate.role`)
- [ ] T012 [P] [US1] Write test `tests/Unit/Server/CallerRoleTest.php`: over HTTP the caller's tools are the role's tools; an undefined or null role gives `[]`
- [ ] T013 [US1] Edit `src/Server/Caller.php`: `role` property; tools from `ActiveSettings::roles()`
- [ ] T014 [P] [US1] Write test `tests/Contract/Roles/RoleToolsHttpTest.php`: a token with role `support` (tools X, Y) lists exactly X and Y; a call to Z answers like an unknown tool and records `policy.hidden` (US1 scenarios 1–2, SC-001: no config change after issuance)

---

## Phase 3: User Story 2 - A role can only narrow a token (P1)

- [ ] T015 [P] [US2] Write test `tests/Unit/Server/CallerCeilingTest.php`: all 9 pairs of token ceiling × role ceiling give the narrower one (SC-002); stdio with `firewall.ceiling` `internal` and role `public` gives `public`; role `personal` with stdio `internal` gives `internal`; no defined role gives null
- [ ] T016 [US2] Add `narrower()` to the ceiling enum `Caller::ceiling()` returns; edit `src/Server/Caller.php::ceiling()`
- [ ] T017 [P] [US2] Write test `tests/Contract/Roles/RoleCeilingFilterTest.php`: token `internal` + role `personal` drops a personal field; token `personal` + role `public` returns public fields only

---

## Phase 4: User Story 3 - Unknown or missing role: deny (P1)

- [ ] T018 [P] [US3] Write test `tests/Contract/Roles/UnknownRoleTest.php`: a token whose role is not in config, and a token without a role ability, each get an empty `tools/list`; every call's response is byte-equal to the unknown-tool response and its receipt has `role.unknown` (SC-003)
- [ ] T019 [US3] Add `ReasonCode::RoleUnknown` (`role.unknown`, decision `hidden`) in `src/Server/ReasonCode.php`; edit `src/Server/PolicyStep.php` to record it for a caller with no defined role
- [ ] T020 [P] [US3] Write test `tests/Unit/Readiness/Checks/TokenRolesCheckTest.php`: active tokens with an undefined role or no role are warned with id, holder and role (`none`), never the token value; all defined passes; database unavailable warns
- [ ] T021 [US3] Create `src/Readiness/Checks/TokenRolesCheck.php`; delete `src/Readiness/Checks/TokenCallersCheck.php` and its test; register in `src/Readiness/CheckRegistry.php`

---

## Phase 5: User Story 4 - stdio callers use roles too (P2)

- [ ] T022 [P] [US4] Write test `tests/Contract/Roles/StdioRoleTest.php`: OS user mapped to `developer` lists its tools; an unmapped user lists none and a call records `role.unknown`; the ceiling is the narrower of `firewall.ceiling` and the role's
- [ ] T023 [US4] Edit `src/Server/Caller.php::current()` to resolve the stdio role from `ActiveSettings::stdioRoles()`
- [ ] T024 [P] [US4] Write test `tests/Contract/Settings/SimulatorTest.php` update: `gate:simulate {tool} {role}`; an undefined role exits 1
- [ ] T025 [US4] Edit `src/Console/SimulateCommand.php` and `src/Server/Simulator.php` to take a role name

---

## Phase 6: User Story 5 - Per-role limits (P2)

- [ ] T026 [P] [US5] Write test `tests/Contract/Roles/RoleLimitsTest.php`: role `calls_per_minute` 2 refuses the third call with `rate.limited`; two tokens with the same role keep separate counters; a role limit above the profile value fails `gate:check` naming `roles.<name>.limits.calls_per_minute`
- [ ] T027 [US5] Edit `src/Server/GateCallTool.php::budget()` to pass the role's effective limits to `Budget`

---

## Phase 7: User Story 6 - Receipts say which role applied (P2)

- [ ] T028 [P] [US6] Write test `tests/Unit/Server/ReceiptRoleTest.php`: `Receipt::write` always writes `role`, default `none`; plus a contract test asserting every receipt captured by the existing contract harness has `role` (SC-004)
- [ ] T029 [US6] Edit `src/Server/Receipt.php`, `src/Server/ResponseBuilder.php`, `src/Server/Failure.php` to write the caller's role
- [ ] T030 [P] [US6] Write test additions in `tests/Unit/Settings/RuleHashesTest.php`: changing a role's tools, ceiling, limits each changes the classification hash (SC-006); same role and ceiling give the same hash
- [ ] T031 [US6] Edit `src/Settings/RuleHashes.php` (`forCall(?Ceiling, ?Role)`) and its caller in `src/Server/ResponseBuilder.php`

---

## Phase 8: Polish

- [ ] T032 Update `docs/tokens.md` (`--role`, ceiling default and narrowing, list column, reissue to change a role, pre-feature tokens) and `docs/configuration.md` (`roles`, `stdio_roles`, per-role limits, reserved `scope`, removal of `callers`) (FR-016)
- [ ] T033 [P] Update `README.md` quick start to issue a token with `--role` and define a role instead of `callers`
- [ ] T034 [P] Update `CHANGELOG.md` Unreleased: roles replace `callers`; new reason `role.unknown`; receipt `role` field
- [ ] T035 Search `src/`, `docs/`, `README.md`, `config/` for `callers` and `TokenCallersCheck`; none left except the validator's pointer message
- [ ] T036 Run `composer style`, `composer stan`, `composer length`, `composer refs`, `composer test`; read each exit code; all 0. Run `composer mutation` (spec 011): `GateTokenAuth`, `PolicyStep` and `Ability` changes keep MSI at or above 90

---

## Dependencies and Execution Order

- Phase 1 before all stories (T001→T002, T003→T004, T005→T006, T007 after T002).
- US1 before US2 and US3 (Caller has a role). US3's T019 may run beside US2.
- US4 after US1 (`Caller::current()`). US5 after US1. US6 after US2 (effective ceiling in the hash).
- Phase 8 last.
