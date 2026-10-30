# Implementation Plan: MCP stdio Foundation

**Branch**: `001-mcp-stdio-foundation` | **Date**: 2026-10-30 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/001-mcp-stdio-foundation/spec.md`

## Summary

A local MCP server for Laravel 12+ over stdio. Tools are denied by default and visible per caller.
`laravel/mcp` provides the protocol and transport; `GateServer` overrides `tools/call` to run
the argument and policy checks and writes a receipt for every call. Visibility uses
`shouldRegister()`. Production refuses to start over stdio.

## Technical Context

**Language/Version**: PHP 8.2+ (`composer.json` `php: ^8.2`)

**Primary Dependencies**: `laravel/framework` ^12 || ^13, `laravel/mcp` ^1.1,
`illuminate/json-schema` (through laravel/mcp)

**Storage**: None. Configuration file and the `context-gate` log channel only (D19).

**Testing**: PHPUnit 11 via `composer test` (paratest); Orchestra Testbench for application tests;
process test for stdio; Infection for mutation testing (dev only, run after US1 and US2 are merged).

**Target Platform**: Developer machine (IDE assistant over stdio). Production is out of scope for
stdio (D20).

**Project Type**: Laravel package (library with artisan commands and a service provider).

**Performance Goals**: A `tools/list` or `tools/call` answer in under 200 ms on an empty
configuration (local process; no network).

**Constraints**: stdout carries only protocol (D11). No network calls in tests. No new runtime
dependency without justification (constitution, principle X).

**Scale/Scope**: Up to 50 tools per application in this feature; no data sources.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How this plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass with condition | No data source in this feature, so nothing reaches the model. Any tool that returns data MUST NOT ship before spec 002 (firewall) is merged. |
| II. Read-Only by Default | Pass | No write tools; `gate:install` writes only the config file, and only once. |
| III. Deny by Default | Pass | `enabled` defaults to false; visibility through `shouldRegister()`; FR-005 identical responses. |
| IV. No Response Without a Receipt | Pass | Receipt written before the response is returned; a receipt failure returns a generic failure (FR-014). |
| V. Code Decides | Pass | Visibility, validation and policy are PHP code; no model is involved. |
| VI. Config Narrows | Pass | Config only enables tools and sets fingerprints; no check can be disabled through config. |
| VII. Failures Quiet to Model | Pass | Reason codes and reference id only (R6, contracts). |
| VIII. Production Authenticated | Pass | stdio refuses production (R8). Sanctum is spec 005. |
| IX. Test-First | Pass with plan | Tests listed in tasks.md precede the implementation tasks. |
| X. Minimal Dependencies | Pass | Runtime: only `laravel/mcp` and the framework. Dev-only: `infection/infection`, justified because mutation testing checks that the contract tests catch logic changes; it is not shipped to consumers and runs as a gate only after US1 and US2 are merged. |

**Result**: all gates pass. No Complexity Tracking entries are needed.

## Project Structure

### Documentation (this feature)

```text
specs/001-mcp-stdio-foundation/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/mcp-tools.md
├── checklists/requirements.md
└── tasks.md           # created by /speckit-tasks
```

### Source Code (repository root)

```text
src/
├── ContextGateServiceProvider.php   # registers config, commands, Mcp::local()
├── Server/
│   ├── GateServer.php               # extends Laravel\Mcp\Server; addMethod('tools/call')
│   ├── GateCallTool.php             # visibility, validation, policy, receipt, delegate
│   ├── Tool.php                     # base class for package and application tools
│   ├── ToolRegistry.php             # tools from config, duplicate and fingerprint checks
│   ├── Caller.php                   # caller id bound in the container
│   ├── Receipt.php                  # JSON line writer for the context-gate channel
│   └── ReasonCode.php               # enum of reason codes
├── Policy/
│   └── ToolPolicy.php               # Gate check per tool
└── Console/
    ├── InstallCommand.php           # gate:install
    └── FingerprintCommand.php       # gate:fingerprint

config/context-gate.php              # published by gate:install
tests/
├── Unit/                            # ToolRegistry, ReasonCode, Receipt, ToolPolicy
├── Contract/                        # contracts/mcp-tools.md as tests
├── EndToEnd/                        # process test: stdout purity, production refusal
└── Fixtures/                        # test tools
```

**Structure Decision**: One package with a single namespace `ContextGate\`. No `src/Mcp` or
`src/Laravel` split: the package is Laravel-only (D1, D36), so folders follow Laravel conventions.

## Complexity Tracking

No violations to justify.
