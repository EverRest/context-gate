# Implementation Plan: Classification Declared on the Model

**Branch**: `015-classification-attributes` | **Date**: 2026-11-01 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/015-classification-attributes/spec.md`

## Summary

Hosts declare column classes on the model with one class attribute, `#[Classify([...])]`. An `EffectiveCatalog`,
built once per process, merges allowlisted models' declarations with the configuration map: the stricter class wins.
Every place that builds `new Catalog(config(...))` today uses it instead, and the classification rule hash covers it.
`gate:check` reports widening, model disagreement and stale declarations; `gate:scan --ci` fails on unclassified
columns of allowlisted models. Drafts stay inert (already true; verified).

## Technical Context

**Language/Version**: PHP 8.2+ (attributes, readonly classes, enums).

**Primary Dependencies**: `laravel/framework` ^12 || ^13 (Eloquent, schema builder). No new dependency.

**Storage**: none. Declarations are code; the merged catalog lives in the container for the process (FR-013).

**Testing**: PHPUnit via `composer test`; Testbench with in-memory SQLite; fixture models under `tests/Fixtures`.
Canary rows seeded for SC-005.

**Target Platform**: stdio, HTTP, artisan.

**Project Type**: Laravel package.

**Performance Goals**: one reflection pass over allowlisted models per process; no schema read on the call path.

**Constraints**: config may never widen a model class (constitution VI); unclassified columns are dropped
(constitution I); commands never edit model files (constitution II); CI output carries names only (constitution VII).

**Scale/Scope**: tens of allowlisted models, hundreds of columns.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How this plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass | No model-wide default; a column with no source of class stays absent and dropped. Unknown class invalidates the catalog (`config.invalid`). Stricter-wins can only remove or narrow. |
| II. Read-Only by Default | Pass | `gate:classify` and `gate:scan` write no model file; CI mode writes nothing. |
| III. Deny by Default | Pass | Only allowlisted models are read (FR-006); no discovery. |
| IV. No Response Without a Receipt | Pass | Unchanged path; the receipt's classification hash covers the merged catalog (FR-011). |
| V. Code Decides | Pass | Merge rule is code; suggestions never apply themselves. |
| VI. Config Narrows | Pass | Config can only raise a class; a lower config class fails `gate:check` and is ignored at runtime by construction. |
| VII. Failures Quiet to Model | Pass | Catalog errors reach the model only as `config.invalid`; names, never values, in `gate:check` and CI output. |
| VIII. Production Authenticated | Pass | Unchanged. |
| IX. Test-First | Pass | Each task pair is test first; characterization tests mark existing draft and hash behaviour. |
| X. Minimal Dependencies | Pass | Reflection and Eloquent only. |

**Result**: all gates pass. No Complexity Tracking entry.

## Project Structure

### Documentation (this feature)

```text
specs/015-classification-attributes/
├── plan.md
├── research.md              # R1–R8
├── data-model.md
├── quickstart.md
├── contracts/classify-attribute-and-scan-ci.md
└── tasks.md
```

### Source Code (repository root)

```text
src/Classification/Classify.php              # new attribute (@api)
src/Classification/Ceiling.php               # stricter()
src/Classification/AllowlistedModels.php     # new: config models + contract models (R4)
src/Classification/ModelDeclarations.php     # new: reflection read, inheritance (R1, R2)
src/Classification/EffectiveCatalog.php      # new: merge, conflicts, map(), catalog() (R3)
src/ContextGateServiceProvider.php           # singleton binding; ruleHashes() uses EffectiveCatalog::map()
src/Server/ContractGuard.php                 # use EffectiveCatalog
src/Server/GateServer.php                    # use EffectiveCatalog
src/Server/FirewallRunner.php                # use EffectiveCatalog
src/Server/Sources/SchemaDescribeTool.php    # use EffectiveCatalog
src/Console/CheckCommand.php                 # use EffectiveCatalog
src/Console/AuditCommand.php                 # use EffectiveCatalog
src/Console/ScanCommand.php                  # --ci mode; AllowlistedModels
src/Console/ClassifyCommand.php              # AllowlistedModels
src/Readiness/Checks/ModelClassCheck.php     # new: conflicts FAIL, stale WARN; registered in CheckRegistry

tests/Fixtures/Classified/                   # fixture models: declared, parent/child, conflicting, stale
tests/Unit/Classification/                   # ClassifyAttribute, CeilingStricter, ModelDeclarations, EffectiveCatalog, AllowlistedModels
tests/Unit/Readiness/Checks/ModelClassCheckTest.php
tests/Contract/Classification/               # runtime merge, config.invalid, hash, scan --ci, drafts, no values
docs/classification.md (new), docs/configuration.md, README.md, CHANGELOG.md
```

**Structure Decision**: one builder (`EffectiveCatalog`) replaces six ad-hoc `new Catalog(config)` calls, so the
runtime, the checks, the audit and the hash cannot disagree. `Catalog` itself stays a plain validated map.

## Design Decisions

- **US1**: attribute read by reflection; unknown class → `InvalidArgumentException` from the builder, which every
  existing caller already maps to `config.invalid` or a `gate:check` FAIL.
- **US2**: stricter-wins in the builder; conflicts only reported by `ModelClassCheck`.
- **US3**: `gate:scan --ci` (research R5) with exit 0/1/2 and JSON on stdout only.
- **US4**: verification only (research R6).
- **US5**: `ruleHashes()` hashes `EffectiveCatalog::map()` (sorted) in place of the raw config map.
- **Spec 009 order**: `EffectiveCatalog::configMap()` is the single reader of the configuration map (research R7).

## Complexity Tracking

No violations.
