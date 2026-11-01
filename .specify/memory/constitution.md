<!--
Sync Impact Report
Version change: 1.0.0 -> 1.1.0
Modified principles: VII (Failures Are Quiet to the Model, Loud to Operators), IX (Test-First)
Added sections: none
Removed sections: none
Proposal: .specify/memory/amendments/2026-11-01-vii-ix.md
Updated specifications: specs/002-context-firewall/spec.md (divergence section now resolved),
  specs/007-configuration/research.md (rationale wording), CLAUDE.md (version)
Follow-up: none
-->

# context-gate Constitution

## Core Principles

### I. Privacy Fails Closed (NON-NEGOTIABLE)

The model MUST receive only fields that an explicit contract projects. A field without a
classification MUST be dropped. A redaction failure MUST drop the field, not pass it through.
A field classified `secret` MUST never be returned in any mode. A field classified `personal`
MUST be returned only as a pseudonym, aggregate, or bucket, never as a raw value.

Rationale: an allowlist cannot leak a field nobody added to it, and fail-closed behavior turns
bugs into missing data instead of disclosed data.

### II. Read-Only by Default

The MVP MUST NOT contain any tool that writes to a monitored system. Write capability MAY be
added later only as a separately specified module, disabled by default, with named operations,
single-record scope, no deletion, a field allowlist, and a signed human approval per call.

### III. Deny by Default

Nothing is visible or callable until it is explicitly allowed by configuration and by the
caller's token abilities. A tool that a caller is not allowed to use MUST NOT appear in
`tools/list`, and calling it MUST return the same response as calling a tool that does not
exist. The real reason MUST be recorded only in the receipt.

### IV. No Response Without a Receipt

Every tool call, including denials and failures, MUST write a receipt. If the receipt cannot be
written, the response MUST NOT be returned. Receipts MUST contain metadata and hashes of the
active rules, and MUST NEVER contain field values.

### V. Code Decides, the Model Judges

Authorization, redaction, input-size limits, path checks, and rate limits MUST be enforced by
deterministic code. No language model MAY sit on the security path. A system prompt, tool
description, or model instruction MUST NOT be relied on as a security control.

### VI. Config Narrows, Never Widens Protections

Configuration MAY lower a limit, select allowed paths or sources, and choose a default
ceiling within hard caps defined in code. Configuration MUST NOT disable pipeline stages,
change their order, raise a limit above its code cap, or allow a denylisted path. A
configuration value above a cap MUST fail `gate:check`.

### VII. Failures Are Quiet to the Model, Loud to Operators

The model MUST receive only a generic message on failure, plus a receipt identifier when the
call failed with an error rather than a refusal. The reason code MUST be recorded in the receipt
only (see III). Exception messages and traces MUST NOT reach the model in any environment,
including when debug output is enabled, and MUST NOT be written to any log, because a message
can embed the value that caused it. Operators get one metadata-only note on the receipt channel:
the receipt identifier, the exception class, the file basename and the line.

### VIII. Production Only on Authenticated Channels

The stdio transport MUST refuse to start when `APP_ENV` is `production`. Production access
MUST use HTTP with a Sanctum token that has an expiry of at most 90 days and the `gate:read`
ability. Every token MUST have an owner and a ceiling, and issuance MUST be recorded in a
receipt.

### IX. Test-First (NON-NEGOTIABLE)

Each rule in this constitution MUST have a test that fails before its implementation exists.
The privacy suite MUST seed known personal data and canary values into fixtures and assert
that none of them appear in any tool response. The stdio process test MUST assert that stdout
contains only protocol messages. No test run by `composer test` MAY use the network. The install
matrix needs the network, so it runs as its own CI job (`scripts/install-test.sh`); its PHPUnit
wrapper is opt-in with `CONTEXT_GATE_INSTALL_TEST=1` and is skipped in the default suite.

### X. Minimal Dependencies

The package MUST support Laravel 12 and later and MUST depend only on `laravel/mcp`,
`laravel/sanctum`, and the Laravel framework packages. Any other runtime dependency MUST be
justified in the specification that introduces it, and MUST NOT be added to satisfy a
convenience. Lumen and Laravel versions below 12 are out of scope (D36).

## Security and Data Handling

- Only classified and allowlisted data reaches a tool response. Schema, code, and documentation
  sources MUST exclude secrets, debug tools, and the paths listed in the denylist.
- Canary values MUST be checked in every response. A match MUST block the response, record
  `canary.hit`, and write a log entry.
- Pseudonym keys MUST NOT outlive the configured scope (session by default, at most 7 days).
- Outbound HTTP for remote sources MUST use a host allowlist, reject private and reserved
  addresses, pin the resolved address, and refuse redirects.
- Session, daily, and per-minute limits MUST be enforced as defined in D23, D25, and D31.

## Development Workflow

- Work follows spec-driven development: specify, clarify, plan, tasks, implement.
- Every merge MUST pass `composer test`, `composer stan` (level 8), and `composer style` with
  exit code 0.
- Files MUST stay within the size limit enforced by `scripts/check-file-length.php`.
- A specification that adds an exposed field, a source, or a tool MUST state its classification
  and ceiling explicitly.

## Governance

This constitution supersedes other practices in this repository. Amendments require a written
proposal, an entry in the Sync Impact Report, and an update to affected specifications.

Versioning follows semantic rules: MAJOR for removing or redefining a principle, MINOR for a
new principle or a materially expanded rule, PATCH for wording changes that keep the meaning.

Every specification plan MUST include a constitution check. Reviewers MUST reject changes that
weaken a principle, including changes that move a rule into configuration.

**Version**: 1.1.0 | **Ratified**: 2026-10-30 | **Last Amended**: 2026-11-01
