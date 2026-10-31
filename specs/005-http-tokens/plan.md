# Implementation Plan: HTTP Access and Tokens

**Branch**: `005-http-tokens` | **Date**: 2026-10-30 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/005-http-tokens/spec.md`

## Summary

Adds the HTTP transport (`Mcp::web`) with four checks on the route: Sanctum authentication, Origin allowlist,
ceiling-ability check, and `gate:read`. Tokens are Sanctum personal access tokens issued only by `gate:token`,
with a mandatory expiry of at most 90 days, a ceiling ability, and an issuer record. `secret` is not a ceiling
(research R3); the Origin check is added by the package (research R5).

## Technical Context

**Language/Version**: PHP 8.2+, Laravel 12+

**Primary Dependencies**: `laravel/mcp` ^1.1 (transport), `laravel/sanctum` ^4 (tokens), framework packages.

**Storage**: `gate_access_tokens` and `gate_token_holders` (new, shipped migrations; the host's `personal_access_tokens` is not used) and `gate_token_issuers` (new, shipped migration).

**Testing**: PHPUnit with Orchestra Testbench and Sanctum in-memory database; HTTP tests through the framework
test client; no network.

**Target Platform**: Application servers (HTTP) and developer machine (command).

**Project Type**: Laravel package.

**Performance Goals**: Token check adds under 10 ms per request (one indexed lookup, Sanctum's).

**Constraints**: Token value never stored or printed twice (FR-008, R4). Ceiling only from the token (FR-012).

**Scale/Scope**: Up to 500 active tokens per application.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How this plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass | Ceiling is enforced by the spec 002 pipeline; `secret` removed as a ceiling (R3). |
| II. Read-Only by Default | Pass | No write tools; token commands write only token rows and receipts. |
| III. Deny by Default | Pass | Every HTTP request needs a token; no ceiling means refusal (R8). |
| IV. No Response Without a Receipt | Pass | Token issuance and revocation write receipts; HTTP tool calls use spec 002 receipts. Refusals at the route are recorded with reason codes. |
| V. Code Decides | Pass | All checks are middleware and command code. |
| VI. Config Narrows | Pass | Config supplies the Origin allowlist and path only; ability set and 90-day maximum are code (FR-006, FR-007). |
| VII. Failures Quiet to Model | Pass | Bodies carry no reason (contract). |
| VIII. Production Authenticated | Pass | HTTP is the production channel; every request is authenticated; stdio stays refused. |
| IX. Test-First | Pass with plan | Tests first in tasks.md, including the 401 and 403 matrix. |
| X. Minimal Dependencies | Pass | `laravel/sanctum` already a planned dependency (D36). No new package. |

**Result**: all gates pass.

## Project Structure

### Documentation (this feature)

```text
specs/005-http-tokens/
├── plan.md
├── research.md          # R1–R11
├── data-model.md
├── quickstart.md
├── contracts/http-and-tokens.md
├── checklists/requirements.md
└── tasks.md             # created by /speckit-tasks
```

### Source Code (repository root)

```text
src/Http/
├── GateTokenAuth.php        # R8: ceiling ability check; sets ceiling on request
├── GateOriginCheck.php      # R5: Origin allowlist, 403
└── HttpRoute.php            # Mcp::web registration with the package middleware (R1, contract)

src/Console/
└── TokenCommand.php         # gate:token issue, --list, --revoke (R2, R4, R6, R11)

src/Token/
├── Ability.php              # package ability allowlist (FR-007)
├── Ceiling.php              # reuse of spec 002 Ceiling without secret (R3)
└── TokenIssuer.php          # writes gate_token_issuers rows with the token

database/migrations/
└── xxxx_create_gate_token_issuers_table.php

config/context-gate.php      # http section (path, origins)

tests/
├── Contract/HttpStatusMatrixTest.php     # 401 / 403 / 200 table (contract)
├── Contract/TokenRefusalTest.php         # no token, expired, revoked, no read, no ceiling
├── Unit/Token/AbilityTest.php            # allowlist, secret refused
├── Unit/Token/TokenCommandTest.php       # --expires required, 90-day max, one-time display
├── Unit/Token/ReceiptNoValueTest.php     # token value absent from receipts and list
└── EndToEnd/HttpInitializeTest.php       # initialize over the route; clientInfo ignored for ceiling
```

**Structure Decision**: Middleware and command code are separate from the stdio server so that the HTTP
surface can be reviewed on its own.

## Complexity Tracking

No violations to justify.
