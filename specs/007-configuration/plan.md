# Implementation Plan: Configuration and Policy Management (MVP)

**Branch**: `007-configuration` | **Date**: 2026-11-01 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/007-configuration/spec.md`

## Summary

Adds the MVP configuration layer: a security profile (strict, balanced) that selects values inside code caps,
an environment rule (production forces strict), whole-file validation shared by `gate:check`, import and
rollback, versioned configuration under storage with a hash per version, a redacted export, per-caller tool
lists, and a policy simulator that reports a decision without calling a handler. Builds on spec 001 (policy
and receipts), spec 002 (`Caps`, `CapsValidator`, `CapsCheck`), spec 003 and 004 (connector timeouts and size
caps), spec 005 (tokens) and spec 006 (`gate:check` checks).

Nothing in this spec changes a pipeline stage or its order. Configuration only selects values at or below the
caps in `src/Pipeline/Caps.php` and in the owning classes.

## Technical Context

**Language/Version**: PHP 8.2+ (`composer.json` `php: ^8.2`)

**Primary Dependencies**: `laravel/framework` ^12 || ^13, `laravel/mcp`. No new dependency: JSON, `hash` and the
filesystem come from PHP and Laravel.

**Storage**: Configuration versions and their index as files under `storage_path('app/context-gate/config')`.
No database table, no cache store (constitution X; spec assumptions).

**Testing**: PHPUnit via `composer test`; Orchestra Testbench; no network. Commands are tested with `artisan()`.

**Target Platform**: Developer machine (stdio) and HTTP (spec 005).

**Project Type**: Laravel package.

**Performance Goals**: Settings are validated once per process at boot; a call reads the cached result.

**Constraints**: Configuration cannot disable a stage, change order or raise a limit above its cap (constitution VI).
No value in logs, receipts, audit lines or exports (FR-007, FR-012). Receipt before response (constitution IV).

**Scale/Scope**: One file, about 20 settings keys, at most 50 versions kept in the index.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How this plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass | Settings never carry field classes beyond the existing catalog; export and audit exclude canary values (R7). |
| II. Read-Only by Default | Pass | Connector `access` accepts only `read`; any other value fails `gate:check` (FR-009). No write tool. |
| III. Deny by Default | Pass | Tool lists per caller are explicit; an unlisted tool stays hidden (existing `ToolRegistry::isVisible`). No `permissive` profile (Out of Scope). |
| IV. No Response Without a Receipt | Pass | Receipt is still written for every call. The log-metadata toggle is dropped because it would drop receipts (R6). Simulator receipts are written too (`simulated: true`). |
| V. Code Decides | Pass | Profile, validation, hashing and rollback are PHP. No model sets configuration (Out of Scope). |
| VI. Config Narrows | Pass | Profiles select values below caps (R2). Unknown keys fail. `redaction`, stage keys and write access are rejected. Session TTL is not configurable because lowering it widens the budget (R4). |
| VII. Failures Quiet to Model | Pass | Invalid settings return `config.invalid` only; violations are written to stderr and the app log with keys, not values. |
| VIII. Production Authenticated | Pass | Production forces strict profile; stdio refusal from spec 001 stays. Tokens remain spec 005. |
| IX. Test-First | Pass | Every task pair in tasks.md is test first. Characterization tests for spec 001 behaviour are marked. |
| X. Minimal Dependencies | Pass | No new package or extension. |

**Result**: all gates pass. One spec-level change needs decision first: the receipt field `simulated` (R5, a
spec 001 receipt change) and the overlay file (R1, a change to the spec 007 "one file" assumption).

## Project Structure

### Documentation (this feature)

```text
specs/007-configuration/
├── plan.md
├── research.md              # R1–R8
├── data-model.md
├── spec.md
└── tasks.md
```

### Source Code (repository root)

```text
config/context-gate.php          # profile, callers, sources.openapi limits, tools.<name> limits (defaults)

src/Settings/                    # new namespace ContextGate\Settings
├── SecurityProfile.php          # enum strict | balanced
├── Profiles.php                 # value maps per profile, keys = cap keys (R2)
├── EnvironmentProfile.php       # APP_ENV -> forced or configured profile (US1)
├── EffectiveSettings.php        # explicit value, else profile value (US2)
├── SettingsSchema.php           # allowed keys, types, forbidden keys
├── SettingsValidator.php        # returns every violation with its key (FR-011)
├── ActiveSettings.php           # validated once per boot; `config.invalid` when false
├── ConfigVersionStore.php       # versions, index, hash, rollback (R3)
├── ConfigOverlay.php            # active version merged over the file at boot (R1)
├── ConfigImporter.php           # validate whole file, then store and audit
├── ConfigExporter.php           # export without secrets or fingerprints
└── ConfigAudit.php              # audit line on context-gate channel

src/Console/
├── ConfigImportCommand.php      # gate:config:import {file}
├── ConfigExportCommand.php      # gate:config:export
├── ConfigRollbackCommand.php    # gate:config:rollback {hash}
└── SimulateCommand.php          # gate:simulate {tool} {caller} {--args=}

src/Config/CapsValidator.php     # CAPS map extended with the new cap keys (existing class, spec 002)
src/Pipeline/Caps.php            # public constants for connector and tool caps (moved from owning classes)
src/Readiness/Checks/SettingsCheck.php   # new; registered in CheckRegistry (spec 006)
src/Readiness/Checks/CapsCheck.php       # reports every cap violation, not the first (spec 002, FR-011)
src/Server/PolicyStep.php        # visibility and policy decision, extracted from GateCallTool
src/Server/Simulator.php         # runs PolicyStep and argument validation, no handler
src/Server/Receipt.php           # optional `simulated` field (R5, needs decision)
src/Server/GateCallTool.php      # uses PolicyStep; refuses with config.invalid when ActiveSettings is invalid
src/Source/Fetch/PinnedClient.php, src/Source/OpenApiParser.php   # take effective timeout and max bytes
src/ContextGateServiceProvider.php     # boot: ConfigOverlay, ActiveSettings; command registration

tests/
├── Unit/Settings/               # profiles, environment, schema, validator, store, audit, exporter
├── Unit/Server/                 # PolicyStep, Receipt simulated field
├── Contract/Settings/           # artisan-level: check, import, rollback, simulate, log privacy
└── EndToEnd/                    # no change; the stdio stdout test stays as it is
```

**Structure Decision**: Settings logic lives in one namespace, `src/Settings/`, with classes that take plain
arrays so that unit tests need no application. Commands are thin. The policy decision is extracted once and used
by both the real call and the simulator, so the two cannot drift (SC-003).

## Design Decisions

### Layout per story

- **US1 (environment)**: `EnvironmentProfile` resolves the profile; `SettingsValidator` fails a weaker profile in
  production; `SettingsCheck` reports it in `gate:check`. Unknown or empty `APP_ENV` is treated as production.
- **US2 (profiles and caps)**: `Profiles` holds two value maps; `EffectiveSettings` picks the explicit value when
  set (validated against the cap), otherwise the profile value. The stage list is not read from config at all.
- **US3 (tools per caller)**: Reuses `Caller::current()` and `ToolRegistry::isVisible`, both from spec 001. New work
  is validation: `callers.<id>.tools` names must exist in `tools`, and unknown caller keys fail.
- **US4 (limits)**: Caps for the OpenAPI timeout and size move to `Caps` and are read by `PinnedClient` and
  `OpenApiParser`. Per-tool `max_bytes` and `calls_per_minute` may only lower the global values. Connector access is
  `read` only.
- **US5 (logging and privacy)**: `redaction` is not a key; any attempt to set it fails as an unknown key with a
  dedicated message. `log_level` is validated only (R6). Metadata toggle is not added (R6).
- **US6 (versions)**: `ConfigVersionStore` writes `versions/<hash>.json` and an `index.json`. `ConfigImporter`
  validates the whole file, then writes the version and the audit line. Rollback re-validates against current caps.
- **US7 (simulator)**: `PolicyStep` is extracted from `GateCallTool` unchanged. `Simulator` runs the same steps
  without the handler, without touching the rate budget, and writes a receipt with `simulated: true`.

### Security profiles and code caps

Only `strict` and `balanced` exist (`SecurityProfile`). `Profiles` maps each limit key to a value at or below its
cap. Strict is the default and holds the lower values (R2). Balanced holds the cap values. The stage list is code
and is identical under both. `permissive` and custom policies are not representable: an unknown profile name fails.

### Environment profile

`EnvironmentProfile::resolve(string $env, ?string $configured): SecurityProfile`:
- `production`, unknown, or empty: strict, whatever is configured.
- Other environments: the configured profile, default strict.
- `SettingsValidator` reports a configured profile weaker than the forced one when `APP_ENV` is production.

### Import, export, validation

- **Import** (`gate:config:import file.json`): parse JSON, run `SettingsValidator` on the whole document, collect
  every violation. Any violation: exit 1, print keys, change nothing. Valid: write `versions/<hash>.json` (temp
  file then rename), update the index, write the audit line, point the index at the new version.
- **Export** (`gate:config:export`): the active document minus `canaries.values`, minus any key matching a secret
  pattern, minus fingerprints (R7). Printed as JSON; an artisan command is not the MCP stdout stream, so this is
  allowed.
- **Validation** is one code path: `SettingsValidator` for `gate:check`, import, rollback and boot.

### Versioning and rollback

- Hash: sha256 of the canonical JSON (sorted keys, no whitespace) of the document, full hex, shown as the first 12
  characters in output.
- Index: ordered list of `{hash, applied_at, actor, previous}`. The last entry is active.
- Rollback to hash H: H must exist in the index. The stored document is re-validated against current caps. Any value
  above a current cap fails with the key named (FR-014). Success points the index at H and writes an audit line with
  `event: config.rollback`.
- Initial state: no version file; the active document is `config/context-gate.php` alone.

### Simulator

`gate:simulate {tool} {caller} {--args=}` runs: visibility (`PolicyStep`), argument validation, policy. It returns
`decision` and `reason` (ReasonCode decision values: `hidden`, `invalid`, `denied`, or `allow`). It never calls the
handler and never admits the rate budget. The receipt is written with `simulated: true` (R5). The simulator does
not evaluate fetch, projection, redaction or budget stages; SC-003 covers the decisions the simulator reports.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Overlay file under storage, while the spec says "one file" (spec assumption) | Import and rollback must not rewrite the PHP config file in a repository; `storage` is writable at run time | Rewriting `config/context-gate.php` from code breaks the file's comments, is unsafe to roll back atomically, and changes a file under version control. Needs spec amendment (R1) |
| Receipt field `simulated` (spec 001 format) | FR-015 requires the receipt to be marked | An audit line instead would leave the receipt format ambiguous for log readers. Needs decision (R5) |
| Extraction of `PolicyStep` from `GateCallTool` | The simulator and the real call must share one decision path (SC-003) | Copying the decision logic would let the two drift |
