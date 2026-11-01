# Implementation Plan: Caller Roles

**Branch**: `010-caller-roles` | **Date**: 2026-11-01 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/010-caller-roles/spec.md`

## Summary

Replaces `callers` with `roles`: a role names tools, a ceiling, optional lower limits and a reserved (empty) `scope`
slot. An HTTP token carries its role as the Sanctum ability `gate:role:<name>`, written once at issuance (no
migration). stdio maps OS user names to roles in `stdio_roles`. A caller with no or an undefined role sees no tools and
every call answers as unknown with the new reason `role.unknown`. The call ceiling is the narrower of the role ceiling
and the token (HTTP) or `firewall.ceiling` (stdio) ceiling; `gate:token --ceiling` defaults to the role ceiling and may
only narrow it. Receipts gain `role`; the classification rule hash covers the effective role and ceiling.
`TokenCallersCheck` becomes `TokenRolesCheck`. Depends on spec 009 (typed settings, `RuleHashes`); spec 014 resolves
scope values in the host per call, so roles carry none.

## Technical Context

**Language/Version**: PHP 8.2+.

**Primary Dependencies**: `laravel/framework` ^12 || ^13, `laravel/mcp`, `laravel/sanctum` ^4. No new dependency.

**Storage**: role on the existing `gate_access_tokens.abilities` JSON column; roles in config only (FR-008). No
migration.

**Testing**: PHPUnit via `composer test`; Testbench with sqlite for token tests; HTTP tests through the existing
contract harness; no network.

**Target Platform**: HTTP (tokens) and stdio.

**Project Type**: Laravel package.

**Performance Goals**: role lookup is an array read on the 009 `ActiveSettings` singleton.

**Constraints**: deny by default, no default role; role cannot widen a token; no token edit path.

**Scale/Scope**: about 15 `src/` files, one new check, one new reason code, one new receipt field.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How this plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass | Effective ceiling is the narrower of role and token/stdio ceiling; no role means no ceiling, which already fails closed. |
| II. Read-Only by Default | Pass | No write tool; roles are config only, no runtime edit path (FR-008). |
| III. Deny by Default | Pass | No default role; undefined or missing role: empty `tools/list`, unknown-tool response, `role.unknown` in the receipt only. |
| IV. No Response Without a Receipt | Pass | Every receipt gains `role` (`none` when unknown); `token.issued` records the role. |
| V. Code Decides | Pass | Role resolution and ceiling minimum are code. |
| VI. Config Narrows | Pass | Role ceiling `secret` fails; role limits must be at or below the profile value in force; `--ceiling` may only narrow the role's. |
| VII. Failures Quiet to the Model | Pass | The model sees the unknown-tool response; the reason is in the receipt only. `gate:check` names token id and holder, never the value. |
| VIII. Production Authenticated | Pass | Every token still stores exactly one ceiling ability and an expiry ≤ 90 days; issuance receipt kept and extended. |
| IX. Test-First | Pass | Every task pair is test first. |
| X. Minimal Dependencies | Pass | None added. |

**Result**: all gates pass. Open owner question: the "no users before 010" assumption vs. the dated `[0.1.0]`
changelog entry (research R8).

## Project Structure

### Documentation (this feature)

```text
specs/010-caller-roles/
├── plan.md
├── research.md            # R1–R8
├── data-model.md
├── quickstart.md
├── contracts/
│   └── roles-and-token-command.md
├── spec.md
└── tasks.md
```

### Source Code (repository root)

```text
config/context-gate.php                       # callers removed; roles => [], stdio_roles => []
src/Settings/Role.php                         # new readonly value object
src/Settings/SettingsSchema.php               # roles, stdio_roles; callers removed with a pointer message
src/Settings/SettingsValidator.php            # role rules (R1) replace the callers cross-check
src/Settings/ActiveSettings.php               # roles(), stdioRoles(); callers() removed
src/Settings/RuleHashes.php                   # forCall(ceiling, role)
src/Classification/Ceiling.php                # narrower() helper (or Token\Ceiling, whichever Caller uses)
src/Token/Ability.php                         # ROLE_PREFIX; isPackageAbility accepts gate:role:<valid name>
src/Token/TokenIssuer.php                     # issue(..., string $role)
src/Http/GateTokenAuth.php                    # at most one role ability; ROLE_ATTRIBUTE
src/Server/GateServer.php                     # binds context-gate.role
src/Server/Caller.php                         # role, tools from role, ceiling = narrower(...)
src/Server/ReasonCode.php                     # RoleUnknown = 'role.unknown'
src/Server/PolicyStep.php                     # role.unknown instead of policy.hidden for role-less callers
src/Server/GateCallTool.php                   # passes role limits to Budget
src/Server/Receipt.php, src/Server/ResponseBuilder.php, src/Server/Failure.php   # role field
src/Server/Simulator.php, src/Console/SimulateCommand.php                       # {role} argument
src/Console/TokenCommand.php                  # --role, ceiling default/narrowing, list role column, no callers line
src/Readiness/Checks/TokenRolesCheck.php      # replaces TokenCallersCheck.php (deleted)
src/Readiness/CheckRegistry.php
docs/tokens.md, docs/configuration.md         # FR-016

tests/Unit/Settings/RoleSettingsTest.php, tests/Unit/Token/RoleAbilityTest.php,
tests/Unit/Server/CallerRoleTest.php, tests/Unit/Readiness/Checks/TokenRolesCheckTest.php,
tests/Contract/Roles/*Test.php
```

**Structure Decision**: the role is a settings concept (`src/Settings/Role.php`) and a token ability
(`src/Token/Ability.php`); the call path only sees `Caller`. No new namespace.

## Design Decisions

See research R1–R6. Order of work: settings (validation, getters) → token ability and issuance → caller resolution
and visibility → ceiling → limits → receipts and hashes → readiness check → docs.

## Complexity Tracking

None.
