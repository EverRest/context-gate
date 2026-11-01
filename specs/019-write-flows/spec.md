# Feature Specification: Multi-Step Write Flows (Intended Design)

**Feature Branch**: `019-write-flows` (not created; no branch until approved)

**Created**: 2026-11-01

**Status**: BLOCKED: NOT IMPLEMENTED

> **This spec requires an explicit amendment of the constitution and owner approval before any plan or code.**
> Writes are out of scope before 1.0: constitution Principle II says the MVP must not contain a write tool, and the
> project scope rule in `CLAUDE.md` says no write tools before 1.0. Principle II allows a later write module only as a
> separately specified, disabled-by-default module with named operations, single-record scope, no deletion, a field
> allowlist and a signed human approval per call. Until the owner amends the scope rule and approves this design, no
> plan, task, test or code for it may be written. This document records the intended design and the open questions. It
> contains no implementation.

**Input**: Owner idea: multi-step write flows exposed through MCP, with explicit human confirmation, dry-run, per-step
receipts, idempotency and a defined stop-point. Depends on specs 002 (firewall, receipts), 007 (`gate:simulate`,
configuration) and 018 (read-only runbooks, which share the step model and must stay read-only).

## Intended User Stories

### User Story 1 - Confirm a flow run outside the caller (Priority: P1)

A flow runs only after a person, using a channel that is not the MCP caller, approves that exact run. The model can
request a run and read its status. It cannot approve a run.

**Why this priority**: The model must never be the one that authorizes a write (constitution V).

**Independent Test**: Request a run through MCP; the run stays `pending_approval` and no step executes; the approval
through the operator channel moves it to `approved`; an approval attempted through MCP is refused.

### User Story 2 - Preview a run before it writes (Priority: P1)

A caller or an operator asks for a dry run. Each step reports what the policy and the validation would decide, using
the same semantics as `gate:simulate` (spec 007, US7). No handler is called.

**Why this priority**: The owner wants to see what a flow would do before it does it.

**Independent Test**: Dry-run a three-step flow; each step reports `allow` or a reason code; the record count and the
database are unchanged.

### User Story 3 - Stop safely and see what happened (Priority: P2)

Each step writes a receipt with metadata only. When a step fails, the flow stops at that step and reports the last
completed step. The run does not continue on its own.

**Why this priority**: Partial writes are the main risk; the operator must know exactly where a run stopped.

**Independent Test**: Make step 2 of 3 fail; the run ends with step 1 `done`, step 2 `failed`, step 3 `not_run`; there
is one receipt per step.

### User Story 4 - Repeat a request without repeating a write (Priority: P2)

Each run carries an idempotency key. A second request with the same key returns the first run's status and writes
nothing.

**Why this priority**: Network retries must not create duplicate writes.

**Independent Test**: Send the same run request twice; one run exists; the second response carries the first run's id.

## Intended Requirements (draft, not approved)

- **WF-R1**: Write tools MUST be a separate tool class, `WriteTool`, with its own gate ability. The read-only gate
  (`gate:read`) MUST NOT be able to enable a write tool. A write tool is disabled until the operator enables it and
  grants the write ability to a caller.
- **WF-R2**: A flow is an ordered list of named steps. Each step names one operation and one target record. A step
  MUST NOT target a set of records (single-record scope).
- **WF-R3**: A flow run MUST be approved by a person through a channel outside the MCP caller (see Q2 and Q3). The
  approval MUST bind the flow version (fingerprint), the ordered steps and a hash of each step's arguments.
- **WF-R4**: Dry-run MUST use the semantics of `gate:simulate`: visibility, configuration check, argument validation and
  policy. It MUST NOT call a handler and MUST NOT write.
- **WF-R5**: Each step MUST write one receipt holding the flow id, the step name, the decision, the reason code, the
  rule hashes and the status. Receipts MUST NOT hold argument or result values (constitution IV, spec 002 FR-024).
- **WF-R6**: Each run MUST carry an idempotency key. A repeated key with the same arguments returns the existing run.
  A repeated key with different arguments is refused.
- **WF-R7**: Each step MUST declare its stop-point: the flow stops at the first failed step. Automatic compensation is
  not in the first version (see Q5).
- **WF-R8**: A write operation MUST NOT delete. It MUST NOT accept or return a `personal` or `secret` value. Responses
  carry the same text stages and canary checks as any tool (spec 002).
- **WF-R9**: Time and count limits MUST be in code: maximum steps per flow, maximum time per run, maximum records per
  run (one per step), maximum runs per token per day. Configuration MAY lower them only (constitution VI).
- **WF-R10**: The model-visible result of a run is the run status, the step statuses and the receipt identifiers. It
  is never a written value (constitution VII).
- **WF-R11**: Writes MUST NOT be enabled by `gate:simulate`, `gate:runbook` or any read-only path.
- **WF-R12**: `gate:check` MUST fail when a write tool is enabled without its ability, when a flow has no approval
  channel configured, or when a limit is above its cap.

## What The Firewall Does NOT Protect

The firewall checks what the model sends and what the tool returns. It does not protect against the following, which
must be stated to the owner and to every caller:

- **Side effects already done.** A step that wrote a row, sent a mail or charged a card is done. The firewall cannot
  undo it, and a stop-point only stops the next step.
- **Writes by host code.** A handler can write other data, call other services or log values. The package sees only
  the handler's return value.
- **Values the caller sent.** An argument that the firewall accepts (for example a free-text note) is written as sent.
  The firewall checks canaries and classes, not business meaning.
- **Downstream systems.** Queues, mail providers, webhooks and other services that receive a write keep their own copy.
- **Partial failures and retries.** A network timeout after a successful write looks like a failure. Idempotency keys
  help only when the handler honours them.
- **Concurrency.** Two approved runs on the same record can interleave unless the handler locks.
- **Operator error.** An approver who approves a wrong run is not stopped by the package.
- **Other write paths.** Any write made outside this module (migrations, commands, other controllers) is out of view.
- **Model reasoning.** The package cannot tell whether the model should have asked for a run.

## Open Questions for the Owner (with recommendations)

- **Q1. Scope rule.** Should write flows be allowed before 1.0 at all? Constitution Principle II and the `CLAUDE.md` scope
  rule both say no before 1.0. **Recommendation**: keep this spec blocked until after 1.0. If you want it earlier, amend
  the scope rule and Principle II in a written proposal first (constitution Governance), and record the amendment in the
  Sync Impact Report.

- **Q2. Approval granularity.** Principle II requires a signed human approval per call; your brief says per run. These
  differ. **Recommendation**: one approval per run that binds the flow version, the ordered steps and each step's argument
  hash, so every call still traces to a signed human decision. Ask the owner to confirm that this satisfies Principle II
  "per call", or to choose per-step approval (more friction, closer to the letter of the rule).

- **Q3. Approval channel.** Which channel gives the confirmation? **Recommendation**: an artisan command run by an operator
  on a shell (`gate:flow:approve <run-id>`), with no MCP tool able to approve. A web approval page would need its own
  authentication and is a separate spec.

- **Q4. Single-record scope versus multi-step.** Principle II requires single-record scope, and a flow touches several
  records over several steps. **Recommendation**: each step is one record; a flow is a list of such steps; no step may
  select a set. A "bulk" flow is refused.

- **Q5. Rollback.** Many writes cannot be undone. **Recommendation**: no automatic compensation in version 1. A failed step
  stops the flow and reports it. A named compensating operation is a later spec, and it needs its own approval.

- **Q6. Idempotency storage.** The cache can be cleared, which would allow a duplicate write after a retry. A database
  table with a unique key is more reliable but adds a migration. **Recommendation**: a table with a unique key, justified
  in the plan (constitution X says a new dependency needs justification; a table is not a dependency, but the decision
  should be explicit).

- **Q7. Dry-run depth.** `gate:simulate` predicts policy and validation only. **Recommendation**: dry-run reports only
  those decisions and states, in the output, that it cannot predict handler or downstream failures. No handler-specific
  preview in version 1.

- **Q8. Personal data in writes.** Should a write ever accept a `personal` value? **Recommendation**: no, in version 1
  (WF-R8). Writes take identifiers and fields from an allowlist that contain no personal values.

## Status and Next Steps

1. Owner decides Q1 (scope). If the answer is "after 1.0", this file stays as the record and nothing else happens.
2. If the answer is "before 1.0", the constitution amendment is written first, then this spec is revised with the
   answers to Q2 to Q8, then `plan.md` with a constitution check, then tests before code (constitution IX).

No branch, plan, task or code for write flows exists or may be created until step 1 is approved.
