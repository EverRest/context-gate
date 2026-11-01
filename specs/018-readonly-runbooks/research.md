# Research: Read-Only Runbooks

## R1. Preemptive timeout (pcntl)

**Decision**: No preemption. A step runs to completion in-process. Its elapsed time is measured with a monotonic clock
after it returns; if it exceeded its timeout, the row is `timeout` and its text is discarded (FR-017). The real bound on
a blocking step is the connection timeout of the framework (Redis, database, HTTP client) that the step uses.

**Rationale**: `pcntl_alarm` is not available on every PHP build (it is absent under web SAPIs and on some hosts), and
a signal can interrupt code in the middle of a write. Owner decision 1 accepts this limit (spec Amendments, 2026-11-01).

**Alternatives rejected**:
- `pcntl` alarm: not always available; interrupts arbitrary code; adds a runtime requirement (constitution X).
- A child process per step: needs `proc_open`, serialises the context, and adds a second execution path to test.
- Running steps in a queue worker: a queue dispatch is out of scope (spec Out of Scope).

**Impact**: The `timeout` status means "returned late", not "stopped". The spec's Edge Cases and the console help text
say so. Re-open only if the owner wants a hard stop.

## R2. Run budget (30 s per run)

**Decision**: The runner checks elapsed time before each step. If the run has used its 30 s budget, the remaining steps
are not started and each is reported as `timeout` with the fixed text `Not run: run limit reached.` A step that is
already running is bounded as in R1.

**Rationale**: Keeps the run cap meaningful without preemption. The fixed text reveals no value.

**Alternatives rejected**: silently dropping the steps (the report would look healthy); marking them `error` (wrong
meaning; `error` implies an exception).

## R3. Console receipt policy

**Decision**: `gate:runbook` writes no receipt for a console run (owner decision 4). An `error` step writes one operator
note on the receipt channel with the exception class, file basename and line (FR-015, constitution VII). No exception
text is printed.

**Rationale**: A console run is an operator action, not a tool call. Constitution IV is about tool calls, so this
satisfies it. The note keeps failures visible without a value.

**Alternatives rejected**: a full receipt per console run (adds receipts that no caller made; the receipt sink would
hold operator noise); no operator note (failures would be silent to operators, against VII).

## R4. Default and cap values

**Decision**: Caps in `src/Pipeline/Caps.php`: `RUNBOOK_STEPS` 20, `RUNBOOK_STEP_TIMEOUT` 5.0 s, `RUNBOOK_STEP_BYTES`
2048, `RUNBOOK_REPORT_BYTES` 8192, `RUNBOOK_RUN_SECONDS` 30.0. Step defaults: timeout 2.5 s, output 512 bytes. Configuration
may lower a value; `gate:check` fails above a cap (constitution VI).

**Rationale**: Owner decision 3 accepts these as the code caps. Caps in the same file as the other limits keep one place
to review.

**Alternatives rejected**: caps in config (constitution VI forbids moving rules into configuration); per-runbook caps
above the global cap (a runbook could widen the report).

## R5. Read-only enforcement

**Decision**: Three layers. (1) `RunbookContext` exposes exactly the seven read methods of FR-004; a reflection test
asserts the public method set. (2) Each step declares `readOnly`; `RunbookValidator` refuses `false` and a missing
value. (3) A source scan test reads every file under `src/Runbook` and fails on a call to a write-like method name
(`dispatch`, `put`, `forget`, `increment`, `insert`, `update`, `delete`, `send`, `post`, `artisan`, `call`).

**Rationale**: The context is the only service a step receives (FR-003), so the surface is the control. The scan guards
the package's own code, not host code (spec Assumptions).

**Alternatives rejected**: a static analyser rule (adds tooling; PHPStan runs at level 8 already and does not express
this); wrapping every facade (a step can call a facade in host code anyway, so the wrapper would only give a false
sense of control).

## R6. Fingerprint

**Decision**: `RunbookFingerprint` builds the canonical JSON of name, description and, per step in order, name,
`readOnly`, timeout and output size; the fingerprint is `sha256` of that JSON. It reuses the canonical JSON helper in
`src/Server/Fingerprint.php` (spec 002).

**Rationale**: One canonicalisation for every fingerprint in the package; the approved value is refreshed by the existing
`gate:fingerprint` (spec 002 Amendments).

**Alternatives rejected**: hashing the PHP class source (changes on comments and formatting; not what FR-020 says).

## R7. Text stages and report shape

**Decision**: The report is built as rows (`step`, `status`, `text`) and the text of each row passes the existing stages
in the order the firewall already uses (invisible-character removal, redaction, canary check). A canary hit blocks the
MCP response and records `canary.hit`. The console applies the same stages to the text it prints (FR-013).

**Rationale**: SC-005 needs console and MCP statuses to match; sharing one pipeline keeps them equal.

**Alternatives rejected**: a runbook-only redaction (a second rule set to keep in step); passing the text unredacted to
the console (the console is still an output path; FR-013 requires the stages).

## R8. Name validation

**Decision**: Runbook names and step names match `^[a-z0-9][a-z0-9_-]{0,63}$`. Case-insensitive duplicates are refused by
`RunbookRegistry` and reported by `gate:check` (spec Edge Cases).

**Rationale**: The name becomes a tool name (`runbook.<name>`); a strict pattern avoids case and dot confusion in tool
lists and receipts.
