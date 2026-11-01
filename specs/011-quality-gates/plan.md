# Implementation Plan: Quality Gates and Maintenance Hygiene

**Branch**: `011-quality-gates` | **Date**: 2026-11-01 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/011-quality-gates/spec.md`

## Summary

Adds three CI gates and two hygiene checks, with no change to any rule, limit, stage order or reason code (FR-017):
a mutation job (Infection, minimum MSI 90 over an explicit security scope, no ignore list), a coverage job (pcov,
floor 92% of `src/` lines), finding records guarded by a meta test, and a `src/` comment check that rejects spec
IDs. The compatibility clean-up (US4) is already done by commit `59777d9`; this plan only verifies it. Implement
first of 011, 009, 010: the gates then guard the 009 and 010 refactors.

## Technical Context

**Language/Version**: PHP 8.2+ (`composer.json` `php: ^8.2`); CI gate jobs on PHP 8.3, locked dependencies.

**Primary Dependencies**: runtime unchanged. New dev dependency `infection/infection` (research R1). Coverage driver
`pcov` is a CI extension, not a composer package.

**Storage**: N/A. Repository files only (`infection.json5`, `tests/findings.php`, `scripts/*.php`).

**Testing**: PHPUnit via `composer test` (paratest). New script tests under `tests/Unit/Scripts/`. Infection and
coverage run only in their own composer scripts and CI jobs (FR-011).

**Target Platform**: GitHub Actions `ubuntu-latest`; maintainer machines.

**Project Type**: Laravel package.

**Performance Goals**: mutation job under 15 minutes on the scope (Infection `--threads=max`); `composer refs` under
2 seconds.

**Constraints**: thresholds live in the repository, never in the job or the environment (FR-004); no per-line
opt-out for either check; no output of fixture secrets or canaries (FR-005).

**Scale/Scope**: about 45 files in the security scope; 396 spec-reference matches in 108 `src/` files to rewrite.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How this plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass | No pipeline change. Mutation of `src/Privacy` and `src/Pipeline` strengthens the tests that prove fail-closed behaviour. |
| II. Read-Only by Default | Pass | No tool added. |
| III. Deny by Default | Pass | `PolicyStep`, `ToolRegistry` are in the mutation scope; no behaviour change. |
| IV. No Response Without a Receipt | Pass | No change to receipts. |
| V. Code Decides | Pass | Gates are deterministic scripts; no model in the loop. |
| VI. Config Narrows | Pass | Thresholds are repository constants that can only be raised by review; no switch turns a gate off (spec Out of Scope). |
| VII. Failures Quiet to the Model | Pass | Comment rewrites keep meaning; no message text that reaches the model changes. |
| VIII. Production Authenticated | Pass | Untouched. |
| IX. Test-First | Pass | Each script gets its failing test first (`tests/Unit/Scripts/*`). `composer test` stays network-free and runs neither Infection nor coverage. Mutation testing is the scaled check of this principle. |
| X. Minimal Dependencies | Pass | `infection/infection` is `require-dev` only, justified in the spec Assumptions and research R1; not in the distribution archive. |

**Result**: all gates pass. No Complexity Tracking entry.

## Project Structure

### Documentation (this feature)

```text
specs/011-quality-gates/
├── plan.md
├── research.md          # R1–R9
├── data-model.md
├── quickstart.md
├── spec.md
└── tasks.md
```

No `contracts/`: the feature adds maintainer tooling only; its interface (composer scripts and their output) is in
data-model.md and quickstart.md.

### Source Code (repository root)

```text
composer.json                       # require-dev infection/infection; scripts mutation, coverage, refs; ci += refs
infection.json5                     # new: contextGate.scope, minMsi 90, logs (github, text), no ignore keys
scripts/mutation.php                # new: reads contextGate.scope, runs Infection with --filter (research R2)
scripts/check-mutation-config.php   # new: forbids ignore keys, annotations, lowered thresholds (research R3)
scripts/coverage-floor.php          # new: reads clover, COVERAGE_FLOOR 92.0 (research R4)
scripts/check-spec-refs.php         # new: tokenizer scan of src/ comments (research R6)
.github/workflows/ci.yml            # + steps length, refs; + jobs coverage, mutation (pcov)
.gitattributes                      # export-ignore infection.json5 (verify scripts/ already ignored)
tests/findings.php                  # new: finding records (research R5)
tests/Unit/FindingRecordsTest.php   # new: meta test over #[Group('finding-*')]
tests/Unit/Scripts/                 # new: CoverageFloorTest, SpecRefsCheckTest, MutationConfigTest
src/**                              # comments only: spec IDs rewritten as rules (US5)
```

**Structure Decision**: Scripts follow the existing `scripts/check-file-length.php` pattern (a pure function plus a
CLI guard, so the unit test calls the function without a process). No `src/` code changes except comments.

## Design Decisions

- **US1**: `composer mutation` = `php scripts/check-mutation-config.php && php scripts/mutation.php`. CI job
  `mutation` (PHP 8.3, pcov, `--threads=max`, `--logger-github`, `--show-mutations`) runs on every push and pull
  request, also when the scope is untouched (spec edge case).
- **US2**: CI job `coverage` (PHP 8.3, pcov) runs `composer coverage`; the floor script writes the job summary.
- **US3**: findings recorded with groups; the meta test runs in `composer test`, so a deleted guarding test fails the
  normal suite (FR-008).
- **US4**: verification only (research R8).
- **US5**: the rewrite happens per directory, each batch followed by `composer refs` on that directory; the check is
  wired into CI only after the count is zero.

## Complexity Tracking

None.
