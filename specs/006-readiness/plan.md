# Implementation Plan: Release Readiness

**Branch**: `006-readiness` | **Date**: 2026-10-30 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/006-readiness/spec.md`

## Summary

One checks registry used by `gate:check` (every check) and `gate:audit` (exposure checks), so the two cannot
disagree. Documentation with automatic checks against the config file. An installation test over fresh Laravel 12
and 13 skeletons. A release workflow that builds the archive and publishes only with an explicit input. Nothing
is published by this feature.

## Technical Context

**Language/Version**: PHP 8.2+, Laravel 12 and 13

**Primary Dependencies**: `laravel/mcp` ^1.1, `laravel/sanctum` ^4, `symfony/yaml`, `ext-intl`

**Storage**: Reads the token table and the catalog file; writes nothing.

**Testing**: PHPUnit (Testbench) for checks, audit and documentation tests; installation script in CI for two
skeletons.

**Target Platform**: Application servers and CI.

**Project Type**: Laravel package with release tooling.

**Performance Goals**: `gate:check` completes in under 5 seconds on an application with 50 contracts.

**Constraints**: No value in any output (FR-014). No publication (FR-020).

**Scale/Scope**: About 20 checks; 4 documents; one workflow.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How this plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass | Audit and check output names settings and classes only; the install test seeds fixture values and asserts they never appear. |
| II. Read-Only by Default | Pass | Audit and check write nothing; the release workflow writes only the archive. |
| III. Deny by Default | Pass | A failed check is a non-zero exit; the release check fails on the placeholder address. |
| IV. No Response Without a Receipt | Pass | Not applicable to these commands; they do not return tool responses. Release-time gates are CI checks. |
| V. Code Decides | Pass | Checks are code; the release gate is a workflow step, not a manual checklist. |
| VI. Config Narrows | Pass | `gate:check` verifies that config values are within caps; it does not relax them. |
| VII. Failures Quiet to Model | Pass | Not applicable; operator-facing only, and every failure names its fix. |
| VIII. Production Authenticated | Pass | `gate:check` verifies the production stdio rule (FR-012). |
| IX. Test-First | Pass with plan | Each check has a failing fixture test first; the documentation checks come first. |
| X. Minimal Dependencies | Pass | No new runtime dependency. The installation script uses only `composer` and the Laravel skeleton. |

**Result**: all gates pass. The release is blocked until the reporting address is filled in (R8), by design.

## Project Structure

### Documentation (this feature)

```text
specs/006-readiness/
├── plan.md
├── research.md          # R1–R8
├── data-model.md
├── quickstart.md
├── contracts/readiness-commands.md
├── checklists/requirements.md
└── tasks.md             # created by /speckit-tasks
```

### Source Code (repository root)

```text
src/Readiness/
├── Check.php                  # interface (R1)
├── CheckResult.php
├── CheckRegistry.php          # every check, registered once
├── Checks/                    # one class per check (R2)
│   ├── CapsCheck.php
│   ├── CatalogCheck.php
│   ├── ContractCheck.php
│   ├── CanaryCheck.php
│   ├── ExtensionsCheck.php
│   ├── OriginCheck.php
│   ├── TokenTableCheck.php
│   ├── TransportCheck.php
│   └── UrlAllowlistCheck.php
└── Audit.php                  # AuditEntry from registry + contracts (R3)

src/Console/
├── AuditCommand.php           # gate:audit [--json]
└── CheckCommand.php           # gate:check [--json] (extends spec 002 partial)

src/ContextGate.php            # VERSION constant (R7)

docs/
├── installation.md
├── configuration.md
├── tokens.md
└── privacy.md
SECURITY.md                    # placeholder until owner fills it (R8)
CHANGELOG.md                   # 0.1.0 entry

scripts/
└── install-test.sh            # R4, not committed output

.github/workflows/release.yml   # R5, dry-run default

tests/
├── Unit/Readiness/            # each check with a failing fixture
├── Contract/AuditTest.php     # audit lists, no values, JSON equal to text
├── Contract/DocsConfigSyncTest.php  # R6 config keys vs docs
├── Contract/VersionTest.php   # R7
└── EndToEnd/InstallMatrixTest.php   # runs scripts/install-test.sh in CI
```

**Structure Decision**: Each check is one class so that a failure names exactly one subject, and so that new checks
from future specs are a registration, not a rewrite.

## Complexity Tracking

No violations to justify.
