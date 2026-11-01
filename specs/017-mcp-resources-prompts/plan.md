# Implementation Plan: MCP Resources and Prompts

**Branch**: `017-mcp-resources-prompts` | **Date**: 2026-11-01 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/017-mcp-resources-prompts/spec.md`

## Summary

Adds read-only MCP resources (allowlisted documents and allowlisted model schemas) and host-defined prompts under the
same rules as tools: deny by default, per-caller visibility where hidden equals absent, fingerprint pins, capability
advertised per caller, and receipts with a `kind` field. A resource read runs the matching tool's handler through the
unchanged admission, arguments, fetch and firewall steps, and shares the tool budgets and rate limit (clarification).
A prompt only fills a fixed template with validated string arguments. `laravel/mcp` v1.1.2 already provides the
protocol methods and capability plumbing (research R1); no new dependency. The gateway also stops routing the vendor
methods it never meant to serve (`completion/complete`, `subscriptions/listen`, `resources/templates/list`,
research R2).

**Order**: after spec 015 (schema resources use the effective catalog, R12); alongside spec 016 (receipt `kind`
field in the structured format, R11).

## Technical Context

**Language/Version**: PHP 8.2+ (`composer.json` `php: ^8.2`)

**Primary Dependencies**: `laravel/framework` ^12 || ^13, `laravel/mcp` (v1.1.2 in `vendor/`: `Server` constants,
`addMethod`, `resolvedCapabilities`, `CursorPaginator`). No new dependency.

**Storage**: none new. Budgets reuse `GateCache::store()` keys of `Budget` unchanged.

**Testing**: PHPUnit via `composer test`; Orchestra Testbench; JSON-RPC messages through the existing server test
harness (`tests/Contract/GateCallHarness.php` pattern) and the stdio process test; fixture docs under
`tests/Fixtures/app`; no network.

**Target Platform**: stdio and HTTP.

**Project Type**: Laravel package.

**Performance Goals**: a list walks at most `Caps::RESOURCE_LIST` (10 000) entries; a read costs what the tool costs.

**Constraints**: hidden == unknown in every answer (III); receipt before response (IV); no push, no subscription,
`listChanged: false` (II, IV); prompts never touch data (I); limits only lowered (VI).

**Scale/Scope**: about 9 new classes, edits to about 12 existing files.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How this plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass | Resource content is the tool's firewall output (same classify, redact, canary, quarantine); `secret` columns stay hidden through the 015 catalog. Prompts insert only the client's own arguments, never data; templates with a canary or redactable text are invalid. No completion (enumeration). |
| II. Read-Only by Default | Pass | Resources are reads; prompts touch nothing. `subscriptions/listen` is removed and no capability offers `subscribe` or `listChanged: true`, so nothing is pushed. |
| III. Deny by Default | Pass | Entries off until enabled, gate ability, caller list, pin. Hidden/unknown share one answer; no capability means every resources/prompts method answers as unknown (R6, R9). Unused vendor methods removed (R2). |
| IV. No Response Without a Receipt | Pass | Every read and get, including refusals, writes a receipt (kind, entry, URI hash) before the response; no notifications exist that would send content without a call. |
| V. Code Decides | Pass | Visibility, URI parsing, path policy, argument validation and budgets are code; a prompt is text for the model, never a control. |
| VI. Config Narrows | Pass | Page size only lowered under `RESOURCE_PAGE`; prompt argument length under `PROMPT_ARGUMENT`; resources add no budget of their own. |
| VII. Failures Quiet | Pass | Fixed error texts (R6); the URI and prompt name are never echoed (unlike the vendor `ReadResource`). |
| VIII. Production Authenticated | Pass | No transport change; HTTP still needs the token. |
| IX. Test-First | Pass | Each implementation task follows a failing test; characterization tests lock today's tool answers before the method map changes. |
| X. Minimal Dependencies | Pass | `laravel/mcp` supports resources and prompts (R1). |

**Result**: all gates pass.

**Spec issues to settle (do not block planning)**:

1. SC-001 ("answers unchanged from today") conflicts with US4's Independent Test and the completion edge case: today
   `resources/list` and `prompts/list` return empty lists and `completion/complete` has its own error (R2). The plan
   follows US4/FR-009; SC-001 should say "capabilities and tool answers unchanged".
2. US4 scenario 2 says a hidden resource's read "answers like an unknown resource". For a caller that sees no resource
   at all the plan answers like an unknown **method** (the capability is absent), so the caller cannot tell the server
   has resources (R6). For a caller that sees some resource, a hidden URI answers `Resource not found.` as stated.
3. FR-014 asks for a hash of the URI; doc paths are low-entropy, so the hash links reads but does not hide the path
   from someone holding the allowlist (R11). Noted, not changed.

## Project Structure

### Documentation (this feature)

```text
specs/017-mcp-resources-prompts/
├── plan.md
├── research.md              # R1–R13
├── data-model.md
├── quickstart.md
├── contracts/resources-prompts.md
├── spec.md
└── tasks.md
```

### Source Code (repository root)

```text
src/Server/Prompt.php                      # @api base class for host prompts (R7)
src/Server/Prompts/PromptRegistry.php      # load, load check, pin, visibility per caller
src/Server/Prompts/GateListPrompts.php     # prompts/list
src/Server/Prompts/GateGetPrompt.php       # prompts/get: visibility, admission, canary, schema, policy, fill, receipt
src/Server/Resources/ResourceRegistry.php  # the two fixed entries, pin, visibility, enumeration (R3, R4)
src/Server/Resources/ResourceUri.php       # build and strictly parse gate:// URIs (R3)
src/Server/Resources/GateListResources.php # resources/list with CursorPaginator
src/Server/Resources/GateReadResource.php  # resources/read through Admission, ArgumentsStep, FetchStep, FirewallStep (R5)
src/Server/Visibility.php                  # one function per caller: visible resources, prompts; used by capabilities and handlers

src/Server/GateServer.php        # own $methods map (R2); resolvedCapabilities() per caller (R9)
src/Server/GateCallTool.php      # budget() extracted as public static Budgets for shared use (R5)
src/Server/FirewallStep.php      # optional renderer for resource contents (R5)
src/Server/ResponseBuilder.php   # receipt(kind:, uriHash:); JSON-RPC error helpers for resources/prompts (R6)
src/Server/Receipt.php           # `kind` always, `uri_hash` optional (R11)
src/Server/ReasonCode.php        # ResourcePinMismatch, PromptPinMismatch
src/Server/Fingerprint.php       # ofResource(), ofPrompt() (R8)
src/Server/Caller.php            # resources and prompts lists
src/Policy/ToolPolicy.php        # `resource:` / `prompt:` prefixes read their entry's gate (R10)
src/Pipeline/Caps.php            # RESOURCE_PAGE, RESOURCE_LIST, PROMPT_ARGUMENT
src/Settings/SettingsSchema.php, src/Settings/SettingsValidator.php   # resources, prompts, callers.*.resources|prompts, resources_page_size
src/Console/FingerprintCommand.php   # --resource, --prompt
src/Console/AuditCommand.php, src/Readiness/Audit.php   # resources and prompts in gate:audit (R13)
src/Readiness/Checks/PromptCheck.php # new; registered in src/Readiness/CheckRegistry.php
config/context-gate.php          # resources, prompts, resources_page_size

tests/
├── Unit/Server/Resources/       # URI parse, registry, enumeration, pins
├── Unit/Server/Prompts/         # registry, load check, schema rules, fill
├── Unit/Server/                 # Receipt kind, Fingerprint, ToolPolicy prefixes, Visibility
├── Contract/Resources/          # list/read/refusals/budgets/receipts/equality with tools
├── Contract/Prompts/            # list/get/refusals/receipts
├── Contract/ProtocolMethodsTest.php   # method map and capabilities per caller
└── EndToEnd/                    # stdio: stdout holds only protocol for a resources/read
```

**Structure Decision**: resources and prompts each get a sub-namespace under `src/Server/`, next to the tool
handlers they mirror. One `Visibility` class backs capabilities, lists and reads, so they cannot disagree. Resource
reads call the existing steps; no firewall stage is duplicated.

## Design Decisions

### Read path

`GateReadResource`: `Visibility` → `ResourceUri::parse` → `Admission::check` (shared key) → `ArgumentsStep` with the
reused tool and pseudo contract → `FetchStep` → `FirewallStep` (shared `Budget`, resource renderer) → receipt → result.
Source failures and path refusals map to `Resource not found.` (receipt keeps the real reason); budgets, rate and
policy to `Request refused.`; failures to `Request failed. Reference: …`.

### Prompt path

`GateGetPrompt`: `Visibility` (including the referenced tools) → `Admission::check` (config, per-minute) →
`FirewallRunner::argumentsRefusal($args, …, null)` (canary) → `ArgumentValidator` on the prompt schema →
`AuthorizesTools::allows('prompt:<name>', …)` → `strtr` fill → receipt → result.

### Capabilities and method map

Per research R2 and R9.

### Documentation

`docs/privacy.md` (resources and prompts follow the firewall; prompts carry no data; no completion; no push; the
receipt `kind` and `uri_hash`; new reason codes), `docs/configuration.md` (keys), `docs/extending.md` (writing a
prompt), README feature list, CHANGELOG Unreleased (new methods; removed vendor methods; receipt `kind`).

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Own handlers instead of vendor `ReadResource`/`GetPrompt` | Vendor handlers have no caller, firewall or receipt and echo the URI | Wrapping vendor `Resource` objects would still route through a handler that writes no receipt (IV) |
| Overriding the vendor `$methods` map | Unused vendor methods are reachable today (R2) | Adding handlers one by one leaves the next vendor method open by default (III) |
| `AuthorizesTools` reused with prefixed names | One policy extension point for hosts | A second interface doubles the host's work; the prefix cannot collide with a tool name |
