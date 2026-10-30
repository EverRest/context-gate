# Implementation Plan: Laravel Sources

**Branch**: `003-laravel-sources` | **Date**: 2026-10-30 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/003-laravel-sources/spec.md`

## Summary

Four read-only sources (routes, Eloquent schema, configuration keys, documentation) exposed as tools
through the spec 002 firewall. Each source implements one `Source` interface. Documentation access is
decided by `DocsPathPolicy` with a denylist in code. Sensitive-name matching is shared (`SensitiveNames`).

## Technical Context

**Language/Version**: PHP 8.2+, Laravel 12+

**Primary Dependencies**: `laravel/framework` ^12 || ^13, `laravel/mcp` ^1.1 (from spec 001),
`ext-intl` (spec 002)

**Storage**: None. Schema introspection reads the database catalog; no row data.

**Testing**: PHPUnit, Orchestra Testbench, SQLite in-memory for schema tests, fixture applications; no network.

**Target Platform**: Developer machine (stdio) and HTTP (spec 005).

**Project Type**: Laravel package.

**Performance Goals**: Route listing under 100 ms for 500 routes; schema description under 200 ms for 50 models.

**Constraints**: No row query (FR-006). Denylist in code (FR-010). No values from config (FR-007).

**Scale/Scope**: Up to 500 routes, 50 models, 2000 config keys per application.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How this plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass | Schema drops `secret` columns; config never reads values; docs go through redaction and canaries. |
| II. Read-Only by Default | Pass | No write paths; tests assert empty query log and unchanged file times (R8). |
| III. Deny by Default | Pass | Sources are tools with contracts; models not in the list are refused (`policy.denied`). |
| IV. No Response Without a Receipt | Pass | Each source call goes through the spec 002 receipt stage. |
| V. Code Decides | Pass | Path policy, denylist and name matching are PHP code. |
| VI. Config Narrows | Pass | Config may add route excludes, models, docs paths and env map entries; it cannot remove a default exclusion or a denylist entry (FR-002, FR-010). |
| VII. Failures Quiet to Model | Pass | Reason codes only (R7). |
| VIII. Production Authenticated | Pass | No change; sources run on the same channels as tools. |
| IX. Test-First | Pass with plan | Tests in tasks.md first; path policy has an adversarial test set (symlinks, case, dotfiles). |
| X. Minimal Dependencies | Pass | No new package. Uses the framework schema inspector and router. |

**Result**: all gates pass.

## Project Structure

### Documentation (this feature)

```text
specs/003-laravel-sources/
├── plan.md
├── research.md          # R1–R8
├── data-model.md
├── quickstart.md
├── contracts/sources.md
├── checklists/requirements.md
└── tasks.md             # created by /speckit-tasks
```

### Source Code (repository root)

```text
src/Source/
├── Source.php              # interface
├── SourceRequest.php
├── SourceResult.php
├── RoutesSource.php        # R2
├── SchemaSource.php        # R3
├── ConfigSource.php        # R4
├── DocsSource.php          # R5
├── DocsPathPolicy.php      # R5, code denylist
└── SensitiveNames.php      # R6

src/Server/
└── SourceTools.php         # four Tool subclasses wiring sources and contracts

config/context-gate.php     # sources section added

tests/
├── Unit/Source/            # policy, sensitive names, redaction of path segments
├── Contract/               # one contract test per source tool
├── Fixtures/app/           # fixture application: routes, models, config, docs, symlinks
└── EndToEnd/               # docs refusal through stdio
```

**Structure Decision**: Sources live in their own namespace because they are data readers, separate from
the pipeline. Tool classes that expose them are thin.

## Complexity Tracking

No violations to justify.
