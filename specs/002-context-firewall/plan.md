# Implementation Plan: Context Firewall

**Branch**: `002-context-firewall` | **Date**: 2026-10-30 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/002-context-firewall/spec.md`

## Summary

Adds the firewall pipeline that every tool response passes through (contracts/firewall-pipeline.md).
Stages run in a fixed order inside `GateCallTool`: fetch, classify check, project, relations,
transform, normalize, redact, canary, quarantine, budget, receipt. Each stage has a fail-closed
behaviour. Builds on spec 001 (GateServer, GateCallTool, ToolRegistry, Receipt, Caller).

## Technical Context

**Language/Version**: PHP 8.2+ (`composer.json` `php: ^8.2`)

**Primary Dependencies**: `laravel/framework` ^12 || ^13, `laravel/mcp` ^1.1, `laravel/sanctum` ^4 (not
used until spec 005), `ext-intl` (new, required for NFKC; see Constitution Check)

**Storage**: Application cache store for pseudonym keys and counters (TTL-bound). No database tables.

**Testing**: PHPUnit via `composer test`; Orchestra Testbench; fixtures with seeded personal data and
canaries; no network.

**Target Platform**: Developer machine (stdio) and HTTP where spec 005 adds it.

**Project Type**: Laravel package.

**Performance Goals**: Pipeline adds under 50 ms for a 16 KB response on a developer machine.

**Constraints**: Limits and stage order are not configurable (constitution VI). Receipt before response
(constitution IV). No value in logs or receipts (FR-024).

**Scale/Scope**: Up to 20 contracts and 200 catalog columns per application.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How this plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass | Unclassified dropped; secret never; personal only transformed; redaction failure drops the field (R1, R3, R7). |
| II. Read-Only by Default | Pass | No write path added. |
| III. Deny by Default | Pass | Contracts are required per tool; a missing contract refuses the response (contract stage 3). |
| IV. No Response Without a Receipt | Pass | Receipt is the last stage; failure refuses the response (R1). |
| V. Code Decides | Pass | All stages are PHP; no model in the path. |
| VI. Config Narrows | Pass | Limits are configurable only down to the hard caps in code (R10); stage order fixed (R1). |
| VII. Failures Quiet to Model | Pass | Reason codes and reference ids only (data-model reason codes). |
| VIII. Production Authenticated | Pass | No change; stdio refusal from spec 001 stays. Tokens are spec 005. |
| IX. Test-First | Pass with plan | Tests in tasks.md precede implementation; privacy suite with seeded PII and canaries. |
| X. Minimal Dependencies | Pass with justification | `ext-intl` is a PHP extension, not a package. It is required because NFKC normalisation is part of the redaction guarantee (R7). The fullwidth fallback was rejected: partial normalisation is a privacy gap. |

**Result**: all gates pass. One justified addition: `ext-intl` (Complexity Tracking).

## Project Structure

### Documentation (this feature)

```text
specs/002-context-firewall/
├── plan.md
├── research.md              # R1–R13
├── data-model.md
├── quickstart.md
├── contracts/firewall-pipeline.md
├── checklists/requirements.md
└── tasks.md                 # created by /speckit-tasks
```

### Source Code (repository root)

```text
app/Gate/
├── classification.php       # app-owned catalog (D14)
└── Contracts/               # app-owned contracts extending ContextContract (D15)

src/
├── Contract/
│   ├── ContextContract.php  # abstract; fields(); load-time validation
│   ├── Field.php            # factories: pseudonym, bucket, date, aggregate, text, relation, plain
│   ├── ContractValidator.php
│   └── RuleHash.php         # tool, contract, classification hashes (R11)
├── Classification/
│   ├── Catalog.php          # loads and validates the catalog
│   └── Ceiling.php          # public < internal < personal < secret
├── Pipeline/
│   ├── Stage.php            # interface: handle(Payload, Closure): Payload
│   ├── ClassifyCheck.php
│   ├── Projector.php
│   ├── Relations.php
│   ├── Transformer.php
│   ├── Normalizer.php
│   ├── Redactor.php         # regex layer (R7)
│   ├── CanaryCheck.php
│   ├── Quarantine.php
│   ├── Budget.php           # counters and truncation
│   └── Caps.php             # hard caps in code (R10)
├── Privacy/
│   └── Pseudonymizer.php    # HMAC, scopes, reverse map (R4, R5)
└── Server/
    └── GateCallTool.php     # extended: runs the Pipeline, then Receipt

config/context-gate.php      # firewall section added
tests/
├── Unit/Contract/           # ContextContract, Field, ContractValidator
├── Unit/Classification/     # Catalog, Ceiling
├── Unit/Pipeline/           # one test file per stage
├── Contract/                # privacy suite, canary, budget, rule hashes
├── Fixtures/                # seeded PII, canaries, injection corpus
└── EndToEnd/                # process test with firewall on
```

**Structure Decision**: Pipeline stages are small classes behind one interface, executed in a fixed list
built in code. Config cannot add or reorder stages (constitution VI).

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| New PHP extension `ext-intl` (principle X) | NFKC normalisation is part of the redaction guarantee; fullwidth or look-alike characters bypass regex otherwise | Partial fallback without the extension leaves a known bypass; the extension is standard in Laravel images |
