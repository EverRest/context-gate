# Implementation Plan: Row Scopes per Caller

**Branch**: `014-row-scopes` | **Date**: 2026-11-01 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/014-row-scopes/spec.md`

## Summary

A contract declares its row scope (`Scope::by([...])`) or that it is unscoped (`Scope::none()`); a contract that does
neither fails `gate:check`. On every call to a scoped contract the host resolves the caller's values through a new
extension interface, `ResolvesScopes`, given the `Caller`. A missing, failing, slow or malformed resolution refuses the
call before the handler runs (`scope.unresolved`). Rows from hand-written tools pass a new first firewall stage,
`scope`, that removes every row outside the caller's values; related records outside the scope become null with
`scope.excluded`; a pseudonym argument outside the scope is refused like an unknown pseudonym. Specs 012 and 013 apply
the same scope inside their SQL through `CallScope::apply`. Receipts record the name, mode and rows removed, never a
value.

**Order**: depends on spec 010 (caller roles; the resolver receives the `Caller`). Must be merged before spec 012 is
enabled on scoped data, and before spec 013. Recommended order: 010, 014, 012, 013 (research R11).

## Technical Context

**Language/Version**: PHP 8.2+ (`composer.json` `php: ^8.2`)

**Primary Dependencies**: `laravel/framework` ^12 || ^13, `laravel/mcp`. No new dependency (constitution X).

**Storage**: None. Scope values live in memory for one call (FR-008). No cache key, no table.

**Testing**: PHPUnit via `composer test`; Orchestra Testbench with in-memory sqlite fixtures; `GateCallHarness` for
call-level tests; no network.

**Target Platform**: stdio (OS-user caller) and HTTP (token caller).

**Project Type**: Laravel package.

**Performance Goals**: one resolver call per scoped call; the row predicate is O(rows x names) with a hash-set lookup
per value; no extra query for hand-written tools (one extra indexed query per pseudonym argument).

**Constraints**: fail closed on every resolver failure; no value or value hash in receipts or logs; stage order in code;
`scopes.timeout_ms` only lowers its cap; files under `src/` stay at or below 250 lines.

**Scale/Scope**: at most 4 scope names per contract, 100 values per name, 10 000 rows per call (existing work bound).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How this plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass | A row with a missing, null or foreign scope value is dropped; any resolver failure refuses the call (R2, R5). A scope never widens a field rule: it runs before `classify` and only removes rows. |
| II. Read-Only by Default | Pass | No write path; the resolver is read by the gate, never exposed as a tool. |
| III. Deny by Default | Pass | Default binding `NoScopes` grants nothing; an undeclared contract is `config.invalid`; no wildcard means "all" (FR-015). Refusals look like other refusals; the reason is in the receipt only. |
| IV. No Response Without a Receipt | Pass | `scope.unresolved` and `scope.excluded` write receipts before the response; the scope summary rides on the existing receipt write. |
| V. Code Decides | Pass | Values come from host code given the `Caller`; never from arguments, `initialize` data or the model (FR-004). |
| VI. Config Narrows | Pass | Only `scopes.timeout_ms` is configurable, below `Caps::SCOPE_TIMEOUT_MS`; the value cap and name cap are code. The new stage is added in code, not config (R5). |
| VII. Failures Quiet to Model | Pass | Generic `Request refused.`; a resolver exception goes to `Failure::hold` (class, file, line only). |
| VIII. Production Authenticated | Pass | No transport change. |
| IX. Test-First | Pass | Every implementation task in tasks.md follows a failing test; the privacy suite seeds sentinel scope values and asserts none appear in responses or receipts (SC-003). |
| X. Minimal Dependencies | Pass | None added. |

**Result**: all gates pass. Two cross-spec updates, both made by tasks: the stage list in
`specs/002-context-firewall/contracts/firewall-pipeline.md` gains `scope` first (spec 014 FR-009 is the rule), and the
reason-code table in `docs/privacy.md` gains two codes.

## Project Structure

### Documentation (this feature)

```text
specs/014-row-scopes/
├── plan.md
├── research.md              # R1–R11
├── data-model.md
├── quickstart.md
├── contracts/scope-resolver.md
├── checklists/requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
src/Contracts/ResolvesScopes.php        # new @api extension interface (R2)
src/Scope/                              # new namespace ContextGate\Scope
├── Scope.php                           # declaration: by([...]) | none() (R1)
├── ScopeGrant.php                      # values([...]) | all() (R2)
├── CallScope.php                       # per-call grants, admits(), apply(), bindingKey(), summary() (R5–R8)
├── ScopeResolution.php                 # calls the resolver, fail-closed table, time limit (R2, R3)
└── NoScopes.php                        # default binding: grants nothing
src/Contract/ContextContract.php        # scope(): ?Scope, default null (R1)
src/Contract/ContractValidator.php      # scope_undeclared, names, columns in catalog (R1)
src/Pipeline/ScopeFilter.php            # new stage `scope`, before classify (R5)
src/Pipeline/Relations.php              # related scope check, scope.excluded drop (R6)
src/Pipeline/Caps.php                   # SCOPE_VALUES 100, SCOPE_NAMES 4, SCOPE_TIMEOUT_MS 2000
src/Server/ScopeStep.php                # resolve, pseudonym-argument check, bind/unbind (R4)
src/Server/GateCallTool.php             # runs ScopeStep; unbinds context-gate.scope in finally
src/Server/FirewallRunner.php           # stage `scope` first
src/Server/ReasonCode.php               # ScopeUnresolved, ScopeExcluded (R9)
src/Server/Receipt.php                  # optional `scope` field (R8)
src/Server/ResponseBuilder.php          # passes CallScope::summary() to the receipt
src/ContextGateServiceProvider.php      # binds ResolvesScopes => NoScopes; scope in contract rule hash (R10)
src/Settings/{SettingsSchema,Profiles}.php, src/Config/CapsValidator.php   # scopes.timeout_ms
src/Source/Pseudo/*Contract.php         # each source contract returns Scope::none()
config/context-gate.php                 # scopes.timeout_ms => null

tests/
├── Fixtures/ScopedEntryContract.php, ScopedAthleteContract.php, ScopedEntryRecord.php, FakeScopes.php
├── Unit/Scope/                         # Scope, ScopeGrant, CallScope, ScopeResolution
├── Unit/Contract/ScopeDeclarationTest.php
├── Unit/Pipeline/ScopeFilterTest.php, RelationsScopeTest.php
├── Unit/Server/ReasonCodeTest.php, ReceiptScopeTest.php (extended / new)
└── Contract/Scope/                     # call-level: rows, refusals, pseudonyms, relations, grant, privacy, fuzz
```

**Structure Decision**: Scope logic lives in one namespace, `src/Scope/`, with one row predicate (`CallScope::admits`)
shared by the stage, the relation loader and the pseudonym check, and one SQL helper (`CallScope::apply`) for specs 012
and 013. The resolver is an interface in `src/Contracts/`, like the other host extension points. The only new call-path
class is `ScopeStep`, between `ArgumentsStep` and `FetchStep`.

## Design Decisions

- **Call path** (R4): `PolicyStep` → `Admission` → `ArgumentsStep` → **`ScopeStep`** → `FetchStep` → `FirewallStep`.
  `ScopeStep` returns early with no work for unscoped contracts and source tools.
- **Stages** (R5): **`scope`**, classify, project, relations, transform, normalize, canary.pre, redact, canary,
  quarantine, budget, receipt.
- **Receipts** (R8): `scope` list on every receipt written after `ScopeStep`; `mode` `unresolved` on a
  `scope.unresolved` receipt.
- **Interfaces for later specs** (R7): `CallScope::apply` and `CallScope::bindingKey` are what specs 012 and 013 call;
  neither is used by this spec's own tools beyond tests.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| New public interface `ResolvesScopes` with one in-package implementation (`NoScopes`) | The spec makes the host the only source of scope values (clarification 2026-11-01); the package cannot know them | Values on the token or role were rejected by the owner (stale after revocation) |
| New pipeline stage `scope` (stage list change from spec 002) | FR-009 requires the check before classify for every hand-written tool | A per-tool check in host code is the leak the spec exists to remove |
| Post-hoc time limit, not pre-emption (R3) | PHP cannot interrupt a synchronous host call without `pcntl` | `pcntl_alarm` is CLI-only and collides with host signals; documented as a limit |
