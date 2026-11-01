# Implementation Plan: One Path to Settings

**Branch**: `009-settings-single-source` | **Date**: 2026-11-01 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/009-settings-single-source/spec.md`

## Summary

`ActiveSettings` (spec 007's validated-once singleton) becomes the only way package code reads its configuration:
typed getters, effective values computed once, fail closed on an invalid document. The 52 direct
`config('context-gate…')` reads outside `src/Settings/` are replaced (research R1) and an architecture test keeps
them out. The six built-in `source_*` tables move into code (`BuiltinClassification`), the published config ships an
empty `classification`, and a host may only hide built-in tables or columns. Receipt rule hashes are computed from the
merged map, effective limits and the call's own ceiling (fixing the HTTP hash that used the stdio ceiling). No limit,
cap, profile value, stage or reason code changes. Depends on 011 (gates); 010 builds on it.

## Technical Context

**Language/Version**: PHP 8.2+.

**Primary Dependencies**: `laravel/framework` ^12 || ^13, `laravel/mcp`, `laravel/sanctum`. No new dependency.

**Storage**: N/A (config file and the spec 007 overlay in storage, unchanged).

**Testing**: PHPUnit via `composer test` (Unit, Contract); Orchestra Testbench; no network.

**Target Platform**: stdio and HTTP transports.

**Project Type**: Laravel package.

**Performance Goals**: settings validated and resolved once per process; a getter is an array read.

**Constraints**: no consumer `min()` or fallback (FR-007); file limit 250 lines in `src/` (`composer length`);
`ActiveSettings` stays `@internal` (FR-014).

**Scale/Scope**: 35 files touched for reads, 6 built-in tables, about 25 schema keys.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How this plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass | Built-in source classes cannot be widened by a host; a hidden column is dropped as `field.unlisted`. Invalid settings never fall back to defaults. |
| II. Read-Only by Default | Pass | No tool or write path added. |
| III. Deny by Default | Pass | Invalid document: `tools/list` empty, calls answer `config.invalid`. |
| IV. No Response Without a Receipt | Pass | `channel()` keeps a defined value on an invalid document so the `config.invalid` receipt is written (research R2). |
| V. Code Decides | Pass | Validation and merge are deterministic PHP. |
| VI. Config Narrows | Pass | Host may only hide built-in tables/columns; reclassification or new columns fail; the `source_` prefix is reserved; effective values computed once in code. |
| VII. Failures Quiet to the Model | Pass | Violations name keys only; the model sees the generic `config.invalid` failure. |
| VIII. Production Authenticated | Pass | Untouched; the HTTP rule hash now records the token ceiling. |
| IX. Test-First | Pass | Every task pair is test first; the architecture test fails before the reads move. |
| X. Minimal Dependencies | Pass | None added. |

**Result**: all gates pass. One spec tension is recorded for the owner: FR-005 vs. constitution IV for `channel`
(research R2).

## Project Structure

### Documentation (this feature)

```text
specs/009-settings-single-source/
├── plan.md
├── research.md            # R1–R8 (R1 = inventory with file:line)
├── data-model.md
├── quickstart.md
├── contracts/
│   └── classification-config.md
├── spec.md
└── tasks.md
```

### Source Code (repository root)

```text
config/context-gate.php                 # classification => [], "keep these entries" comment removed
src/Settings/ActiveSettings.php         # typed getters, effective values, InvalidSettings on read when invalid
src/Settings/InvalidSettings.php        # new LogicException
src/Settings/ToolEntry.php              # new readonly value object
src/Settings/BuiltinClassification.php  # new: six source_* tables
src/Settings/ClassificationMerge.php    # new: effective(), violations()
src/Settings/RuleHashes.php             # new: moved from ContextGateServiceProvider::ruleHashes, per-ceiling classification hash
src/Settings/SettingsSchema.php         # typed leaves replace every `any`
src/Settings/SettingsValidator.php      # adds ClassificationMerge::violations
src/Settings/EffectiveSettings.php      # static readers removed; for() kept, no above-cap fallback
src/Settings/ConfigOverlay.php          # install(): the overlay step moved out of the provider
src/ContextGateServiceProvider.php      # uses ConfigOverlay::install(), RuleHashes; no config('context-gate…') read
src/Server/*.php, src/Server/Sources/*.php, src/Http/*.php, src/Policy/ToolPolicy.php,
src/Source/ConfigSource.php, src/Source/RoutesSource.php, src/Support/GateCache.php,
src/Runbook/RunbookRegistry.php, src/Pipeline/Budget.php, src/Console/*.php     # read through ActiveSettings
src/Readiness/Checks/SettingsCheck.php  # reports ActiveSettings::violations()
src/Console/CheckCommand.php            # ReadinessContext from ActiveSettings::document(); --json `effective`
docs/configuration.md                   # merge rule, hiding, reserved prefix (FR-015)

tests/Unit/Settings/                    # SingleSettingsPathTest, ActiveSettingsTypedTest, ClassificationMergeTest,
                                        # SettingsSchemaTypesTest, RuleHashesTest
tests/Contract/Settings/                # CheckRuntimeAgreeTest, BuiltinSourceTablesTest, RuleHashEffectiveTest
```

**Structure Decision**: everything that reads or derives settings lives in `src/Settings/`; consumers only call
getters. Existing classes are extended before new ones are added (ActiveSettings, SettingsSchema, ConfigOverlay).

## Design Decisions

- **US1**: one object (`ActiveSettings`) is validated once and used by both `gate:check` and the runtime (research R5).
- **US2**: consumers move file by file, each with its existing tests as the safety net; the architecture test lands
  first, failing, with the inventory as its expected list, and turns green when the last read moves.
- **US3**: built-in map in code; host hides with `false` (contract).
- **US4**: `RuleHashes::forCall(?Ceiling)`; tool and contract hashes unchanged.

## Complexity Tracking

None.
