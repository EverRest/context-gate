# Implementation Plan: OpenAPI Source and Developer Tooling

**Branch**: `004-openapi-and-dx` | **Date**: 2026-10-30 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/004-openapi-and-dx/spec.md`

## Summary

An OpenAPI 3.x source behind the spec 003 `Source` interface, with a network fetch designed around DNS pinning
and refusal of internal addresses (R2). Four developer commands: `gate:scan` and `gate:classify` (drafts only),
`gate:preview` (exact output through the real pipeline), `gate:canary` (values only). None of them enable a
tool, read a row, or print a value.

## Technical Context

**Language/Version**: PHP 8.2+, Laravel 12+

**Primary Dependencies**: `laravel/framework` ^12 || ^13, `laravel/mcp` ^1.1, `symfony/yaml` (new, required;
see Constitution Check)

**Storage**: `storage/context-gate/scan.json` only when requested. No database writes.

**Testing**: PHPUnit, Orchestra Testbench, injected DNS resolver and fake HTTP transport; no network.

**Target Platform**: Developer machine (commands), stdio and HTTP (source tool).

**Project Type**: Laravel package.

**Performance Goals**: Fetch of a 2 MB document under the 5-second timeout; parse under 500 ms.

**Constraints**: Pinned address (R2). No redirects. No output of values (FR-018).

**Scale/Scope**: Up to 5000 endpoints per document; 200 models for scan.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How this plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass | Examples dropped by default; redaction and canaries for the `redacted` mode; unclassified draft columns are `secret`. |
| II. Read-Only by Default | Pass | Commands write only drafts and an explicit scan report; no write tools. |
| III. Deny by Default | Pass | Empty URL allowlist refuses all URLs; no tool is enabled by any command. |
| IV. No Response Without a Receipt | Pass | Preview and tool calls write receipts; a receipt failure refuses output (spec 002). |
| V. Code Decides | Pass | Address checks, pinning and redirect handling are code. |
| VI. Config Narrows | Pass | Config supplies the allowlist and the examples mode; the 5-second timeout, 2 MB cap and refusal ranges are fixed in code. |
| VII. Failures Quiet to Model | Pass | Refusals return codes only; no host or address in the response. |
| VIII. Production Authenticated | Pass | URL fetch is a server-side request; it is refused for any host not allowlisted, including in production. |
| IX. Test-First | Pass with plan | Rebinding and range tests first (tasks). |
| X. Minimal Dependencies | Pass with justification | `symfony/yaml` is required: YAML OpenAPI documents are the common format, and a hand parser would be a larger risk. Justified in Complexity Tracking. |

**Result**: all gates pass. One justified addition: `symfony/yaml`.

## Project Structure

### Documentation (this feature)

```text
specs/004-openapi-and-dx/
├── plan.md
├── research.md          # R1–R9
├── data-model.md
├── quickstart.md
├── contracts/openapi-source.md
├── checklists/requirements.md
└── tasks.md             # created by /speckit-tasks
```

### Source Code (repository root)

```text
src/Source/
├── OpenApiSource.php        # implements Source (spec 003)
├── OpenApiParser.php        # version check, size check, endpoints
├── ExamplePolicy.php        # drop or redact examples (R3)
└── Fetch/
    ├── UrlGuard.php         # R2 steps 1–4: scheme, allowlist, ranges
    ├── Resolver.php         # DNS once; injectable for tests
    ├── PinnedClient.php     # R2 steps 5–8: pinned connect, no redirects, timeout, size
    └── AddressRanges.php    # IPv4 and IPv6 refusal list

src/Console/
├── ScanCommand.php          # gate:scan
├── ClassifyCommand.php      # gate:classify
├── PreviewCommand.php       # gate:preview
└── CanaryCommand.php        # gate:canary

src/Classification/
└── DraftWriter.php          # CatalogDraftLine rendering (R6)

config/context-gate.php      # sources.openapi section added

tests/
├── Unit/Fetch/              # UrlGuard, AddressRanges, Resolver rebinding test
├── Unit/Source/             # OpenApiParser, ExamplePolicy
├── Unit/Console/            # draft writer, canary shape
├── Contract/                # openapi.endpoints contract, preview byte-equality
└── Fixtures/openapi/        # valid 3.1, Swagger 2.0, oversized, external ref, examples with seeded values
```

**Structure Decision**: Network code is isolated in `Fetch/` so that the security-relevant part can be tested
with an injected resolver and transport, without sockets.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| `symfony/yaml` runtime dependency (principle X) | Most OpenAPI documents are YAML; a hand parser for YAML would be a bigger security risk than the dependency | Accepting JSON only would exclude most real documents |
