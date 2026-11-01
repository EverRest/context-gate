# Implementation Plan: Read-Only Runbooks

**Branch**: `018-readonly-runbooks` | **Date**: 2026-11-01 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/018-readonly-runbooks/spec.md` (Accepted for planning, 2026-11-01)

## Summary

A developer writes a runbook as a PHP class in the host application: an ordered list of read-only steps. Each step
receives a `RunbookContext` that offers seven read checks (booleans and integers only). `gate:runbook {name}` runs a
runbook and prints one row per step. The same runbook is exposed as the source tool `runbook.<name>`, which passes
through the existing firewall (text stages, canary check, receipt) and is disabled until enabled. `gate:check` validates
every registered runbook; the fingerprint covers the step list so a change needs re-approval.

Builds on spec 001 (receipts), 002 (contracts, fingerprints, firewall, text stages), 003 (source tools), 006
(`gate:check` registry) and 007 (configuration, tool enablement, caps). No change to any pipeline stage or its order.

## Technical Context

**Language/Version**: PHP 8.2+ (`composer.json` `php: ^8.2`)

**Primary Dependencies**: `laravel/framework` ^12 || ^13 (Cache, Queue, DB, Config, Route, Artisan), `laravel/mcp`.
No new dependency (constitution X).

**Storage**: None. A run holds nothing between calls; receipts use the existing receipt sink (spec 001). No table, no
cache key.

**Testing**: PHPUnit via `composer test` (paratest); Orchestra Testbench; commands tested with `artisan()`; no network.
Fake runbooks and steps live in `tests/Fixtures/Runbook/`.

**Target Platform**: Laravel host applications, console and MCP stdio/HTTP transports.

**Project Type**: Laravel package (library with console commands and an MCP server).

**Performance Goals**: A full run is bounded by the 30 s run cap; the report is at most 8 KiB.

**Constraints**: Step output 2048 bytes max; 20 steps max; 5 s per step cap; no step may write through the package
surface; no value from the environment, config or database appears in output (FR-009).

**Scale/Scope**: Typically 3 to 10 steps per runbook; a handful of runbooks per host application.

## Constitution Check

| Principle | Status | Note |
|---|---|---|
| I. Privacy Fails Closed | Pass | Context returns only bool and int; output runs the text stages (FR-010). A step that throws returns the generic text. |
| II. Read-Only by Default | Pass | No write method on the context; `readOnly` declaration checked by `gate:check`; source scan test under `src/Runbook`. **Note**: a step can still write in host PHP code (spec Assumptions). This is the accepted limit, stated in the spec and in the quickstart. |
| III. Deny by Default | Pass | `runbook.<name>` disabled until `tools.runbook.<name>.enabled`; hidden tools answer as unknown tools (`policy.hidden`). |
| IV. No Response Without a Receipt | Note | Every MCP call writes a receipt. The console run writes none, because it is not a tool call (owner decision 4, spec Amendments). The operator note for an `error` step goes to the receipt channel (FR-015). |
| V. Code Decides, the Model Judges | Pass | Runner, limits, validation, redaction and status mapping are deterministic code. No model on the path. |
| VI. Config Narrows, Never Widens | Pass | Limits are caps in `src/Pipeline/Caps.php`; `runbooks` config only maps names to classes and can lower limits. A limit above its cap fails `gate:check`. |
| VII. Failures Quiet to Model, Loud to Operators | Pass | Exception text never reaches output; one operator note with class, basename and line. |
| VIII. Production Only on Authenticated Channels | Pass | MCP access stays on the existing authenticated HTTP and stdio paths; no new access path. |
| IX. Test-First | Pass | Every implementation task in `tasks.md` is preceded by a failing test task. |
| X. Minimal Dependencies | Pass | No new dependency. Fingerprint reuses `src/Server/Fingerprint.php`. |

**Result**: no conflict. Two Notes (II, IV), both already decided in the spec. No constitution amendment needed.

## Project Structure

### Documentation (this feature)

```text
specs/018-readonly-runbooks/
├── spec.md
├── plan.md              # this file
├── research.md          # decisions R1 to R7
├── data-model.md        # runbook, step, step result, report, context
├── quickstart.md        # how a developer writes and runs one runbook
├── contracts/
│   ├── console-output.md        # gate:runbook output and exit codes
│   └── runbook-source-tool.md   # runbook.<name> source tool contract
├── tasks.md
└── checklists/requirements.md
```

### Source Code (repository root)

```text
src/
├── Contracts/
│   └── Runbook.php                 # interface: name(), description(), steps(): list<RunbookStep>
├── Runbook/
│   ├── RunbookStep.php             # name, readOnly, timeout, maxBytes, run(RunbookContext): StepResult
│   ├── StepStatus.php              # backed enum: ok, warn, fail, timeout, error
│   ├── StepResult.php              # status + text
│   ├── RunbookContext.php          # the seven read methods (FR-004), nothing else public
│   ├── RunbookRegistry.php         # reads config `runbooks`, validates names, resolves classes
│   ├── RunbookRunner.php           # runs steps in order, timing, error mapping, run budget
│   ├── Report.php                  # rows, total size cap (8 KiB), dropped count
│   ├── RunbookFingerprint.php      # canonical JSON of name, description, steps; sha256
│   └── RunbookValidator.php        # rules used by gate:check (FR-021)
├── Readiness/Checks/
│   └── RunbookCheck.php            # gate:check check registered in CheckRegistry (spec 006)
├── Console/
│   └── RunbookCommand.php          # gate:runbook {name}
├── Source/
│   └── RunbookTool.php             # source tool runbook.<name>; uses the spec 003 source tool pattern
└── Pipeline/
    └── Caps.php                    # edit: RUNBOOK_STEPS, RUNBOOK_STEP_TIMEOUT, RUNBOOK_STEP_BYTES,
                                    #       RUNBOOK_REPORT_BYTES, RUNBOOK_RUN_SECONDS

config/context-gate.php             # edit: `runbooks` key (empty array) and `tools.runbook.*` keys (documented)
src/ContextGateServiceProvider.php  # edit: register command and check

tests/
├── Unit/Runbook/                   # registry, runner, report, fingerprint, validator, context surface
├── Contract/Runbook/               # console, MCP, canary, hidden, receipt, source scan, fingerprint, gate:check
├── Fixtures/Runbook/               # fake runbooks: passing, warning, failing, throwing, slow, oversize, writing
```

**Structure Decision**: One `src/Runbook/` namespace holds the model and the runner; the console command and the source
tool are thin adapters, as the simulator (spec 007 US7) is. The text stages are not copied: the source tool and the
console both hand the report text to the existing stages (`src/Server/FirewallRunner.php`, `src/Pipeline/*`).

## Complexity Tracking

No constitution violation to justify.

## Cross-spec tasks (from review 018-REV, not yet applied)

- [ ] X001 Edit `specs/007-configuration/spec.md` to add `runbooks` to its valid top-level configuration keys (unknown keys are `config.invalid`); record it in its Amendments section. Spec 018 FR-002. Tasks T004 and T006.
- [ ] X002 Edit `specs/002-context-firewall/spec.md` (Amendments section) and `specs/002-context-firewall/data-model.md` to add the runbook source (`runbook.<name>`, classification `internal`, ceiling `internal`). Spec 018 FR-011. Task T007.
