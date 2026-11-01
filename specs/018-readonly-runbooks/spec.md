# Feature Specification: Read-Only Runbooks

**Feature Branch**: `018-readonly-runbooks`

**Created**: 2026-11-01

**Status**: Accepted for planning (2026-11-01)

**Input**: Owner request: a developer writes a runbook as a PHP class in the host application. A runbook is a
sequence of read-only steps (check environment variables by name, check queue or cache connectivity, read
migration status, count failed jobs, check that a route exists). The console command `gate:runbook <name>` runs
it and prints a report. The same runbook is exposed as the MCP source tool `runbook.<name>`, with a contract, a
fingerprint and the firewall. Builds on specs 001 (receipts), 002 (contracts, fingerprints, firewall), 003 (source
tools) and 007 (configuration, tool enablement, caps).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Run a runbook from the console (Priority: P1)

A developer writes a runbook class in the host application, registers it, and runs `gate:runbook <name>` on the
command line. The command prints one line per step: the step name, its status and a short text. The developer sees
at a glance which checks pass, which warn and which fail.

**Why this priority**: The console report is the first use of a runbook and the only one that works without MCP.

**Independent Test**: Register a runbook with three steps (one passes, one warns, one fails). Run the command; the
report has three rows with those statuses, and the exit code is non-zero because one step failed.

**Acceptance Scenarios**:

1. **Given** a registered runbook with three steps, **When** `gate:runbook <name>` runs, **Then** it prints one row
   per step with the step name, the status and the text, in declared order.
2. **Given** a runbook name that is not registered, **When** the command runs, **Then** it exits with code 2 and
   prints the registered names only.
3. **Given** every step is `ok` or `warn`, **When** the command finishes, **Then** it exits with code 0.
4. **Given** one step is `fail`, `timeout` or `error`, **When** the command finishes, **Then** it exits with code 1.

---

### User Story 2 - Ask for the same report through MCP (Priority: P1)

A caller with access to the tool calls `runbook.<name>` and receives the same report as rows. The report goes through
the same firewall as every other tool response: the caller sees the text marked as untrusted, the response is checked
for canary values, and the call is written to a receipt.

**Why this priority**: The owner wants the assistant to ask "is the environment healthy" without a person at the
console.

**Independent Test**: Enable `runbook.<name>` for a caller and call it. The rows match the console report. Disable
it; the same call returns the same response as an unknown tool.

**Acceptance Scenarios**:

1. **Given** `runbook.<name>` is enabled and listed for the caller, **When** the caller lists tools, **Then** the tool
   appears; **When** the caller calls it, **Then** the rows match the console statuses for the same environment.
2. **Given** `runbook.<name>` is disabled, **When** any caller calls it, **Then** the response equals the response for
   an unknown tool and the receipt records `policy.hidden` (spec 007, US3).
3. **Given** a step text that contains a canary value, **When** the report is built, **Then** the response is blocked
   with the generic failure, and the receipt records `canary.hit`.

---

### User Story 3 - Steps cannot write or reveal values (Priority: P1)

A step receives a read-only context. The context offers a fixed set of read checks, and each returns a boolean or an
integer. A step cannot write, dispatch a job, send a request through the package, or read a value into the report.
The package never calls a write method.

**Why this priority**: Constitution II (read-only) and I (privacy fail closed) are the reasons this feature exists in
the MVP. A runbook that can write or print a secret breaks both.

**Independent Test**: Assert the public method set of the context object equals the fixed list in FR-004. Run a
runbook whose step tries to read a secret value; the report contains no value.

**Acceptance Scenarios**:

1. **Given** a step that calls a method not on the context, **When** the step runs, **Then** it is reported as
   `error` and the caller sees the generic message.
2. **Given** the environment variable `APP_KEY` holds a value, **When** a step checks it, **Then** the step receives
   `true` only, and no output contains the value.
3. **Given** a step declares `readOnly` false, **When** `gate:check` runs, **Then** it fails and names the runbook and
   the step.

---

### User Story 4 - A changed runbook needs re-approval (Priority: P2)

A runbook's step list is part of its fingerprint. When a developer adds, removes or reorders a step, or changes a
step's declared timeout or output limit, the fingerprint changes. The tool then waits for the operator to refresh
its approved fingerprint, as for every other source tool.

**Why this priority**: The steps decide what the runbook can reveal. A change must not reach an assistant unreviewed.

**Independent Test**: Change one step's name; the fingerprint changes; with the old approved fingerprint, the tool is
refused until `gate:fingerprint` is run for it.

**Acceptance Scenarios**:

1. **Given** an approved fingerprint, **When** a step is added, **Then** the fingerprint differs and `gate:check` fails
   for `runbook.<name>` until the approved value is refreshed.
2. **Given** no change, **When** the fingerprint is computed twice, **Then** the two values are identical.

---

### User Story 5 - `gate:check` validates runbooks (Priority: P2)

`gate:check` lists every registered runbook and fails for any runbook that is not valid: a class that does not exist,
a step that is not declared read-only, a duplicate step name, a limit above its cap, or no steps.

**Why this priority**: Failures found before a deploy cost less than failures found by a caller.

**Independent Test**: Register one valid and one invalid runbook; `gate:check` passes the first and fails the second,
naming the runbook and the rule.

**Acceptance Scenarios**:

1. **Given** a runbook whose class does not exist, **When** `gate:check` runs, **Then** it fails and names the
   runbook key.
2. **Given** a runbook with 21 steps (cap 20), **When** `gate:check` runs, **Then** it fails and names the runbook.
3. **Given** a valid runbook, **When** `gate:check` runs, **Then** it passes for that runbook.

---

### Edge Cases

- A step throws an exception: the step is reported as `error` with the generic text. The exception message never
  reaches the console report or the MCP response. The operator gets the exception class, the file basename and the
  line on the receipt channel (constitution VII).
- A step runs past its timeout: the step is reported as `timeout` when it returns. PHP cannot interrupt a blocking
  call in this design; a step is bounded in practice only by the timeouts of the connections it uses (see
  Assumptions).
- A step returns text longer than its output limit: the text is cut at the limit and the row says so.
- The whole report is larger than 8 KiB: the rows that do not fit are dropped, the report says how many were dropped,
  and no row is cut in the middle of a word.
- Two runbooks register the same name, or a name differs only in case: `gate:check` fails.
- A step's text contains a redaction match (an email, a token shape): the redaction layer runs on it before output, as
  for every text field (spec 002, FR-014).
- The database is unreachable: the check step reports `fail` or `error`, the other steps still run.
- Migration status cannot be read because the migrations table does not exist: the step reports `fail` with a fixed
  text; no exception text is shown.
- A runbook has steps but the caller's ceiling is `public` and the report is `internal`: the report is not returned to
  that caller (spec 002, FR-007).
- `gate:runbook` runs while `runbook.<name>` is disabled: the console still runs, because the console is the operator's
  path (see FR-013).

## Requirements *(mandatory)*

### Functional Requirements

**Definition and registration**

- **FR-001**: A runbook MUST be a class in the host application that implements the package's `Runbook` interface and
  returns an ordered list of steps. Each step has a name, a declared `readOnly` flag, a timeout in seconds and a
  maximum output size in bytes.
- **FR-002**: Runbooks MUST be registered in a new configuration key `runbooks` that maps a runbook name to its class.
  A runbook name MUST match `^[a-z0-9][a-z0-9_-]{0,63}$`. Each registered runbook yields exactly one tool entry named
  `runbook.<name>`.

**Read-only by construction**

- **FR-003**: A step MUST receive a `RunbookContext` and no other service. The context MUST NOT expose a write, dispatch,
  send or HTTP method.
- **FR-004**: The public methods of `RunbookContext` MUST be exactly: `envPresent(string $name): bool`,
  `configPresent(string $key): bool`, `cacheReachable(string $store): bool`, `queueReachable(string $connection): bool`,
  `migrationsPending(): int`, `failedJobCount(): int`, `routeExists(string $nameOrUri): bool`. Every method returns a
  boolean or an integer. A test asserts this list.
- **FR-005**: Every context method MUST be a read. A reachability check MUST NOT dispatch a job and MUST NOT write a
  cache key. A test asserts that the runbook code under `src/Runbook` contains no call to a write method.
- **FR-006**: `gate:check` MUST fail when a step does not declare `readOnly` as `true`. The runbook is refused on every
  call until the check passes.
- **FR-007**: The package MUST NOT make an HTTP request, dispatch a job, or send a notification on behalf of a runbook.

**Output and privacy**

- **FR-008**: A step result MUST be a status and a text. The status is one of `ok`, `warn`, `fail`, `timeout`, `error`.
  The text is a short string; it is cut at the step's maximum output size.
- **FR-009**: Runbook output MUST contain names, counts, booleans and statuses. The context never returns a stored value,
  so a step cannot copy an environment value, a config value or a row into its text through the context.
- **FR-010**: Each step text MUST pass the same text stages as any tool response: invisible-character removal,
  redaction, canary check (spec 002, FR-013, FR-014, FR-017). A canary hit blocks the MCP response and records
  `canary.hit`.
- **FR-011**: Runbook output classification is `internal`. The `runbook.<name>` tool ceiling MUST be `internal`. A caller
  whose ceiling is below `internal` does not receive the report (spec 002, FR-007).
- **FR-012**: Over MCP, the report is returned as rows (`step`, `status`, `text`) marked `untrusted: true` with the source
  name `runbook.<name>` (spec 002, FR-015). The rows are not instructions to the model.

**Console**

- **FR-013**: `gate:runbook {name}` MUST run a registered runbook whether or not `runbook.<name>` is enabled, because the
  console is the operator's path. It MUST print one row per step in declared order, and it MUST apply FR-010 to the
  text it prints.
- **FR-014**: `gate:runbook` exit codes: 0 when every step is `ok` or `warn`; 1 when any step is `fail`, `timeout` or
  `error`; 2 when the name is not registered.
- **FR-015**: `gate:runbook` writes nothing to the receipt channel for a console run, and it prints no exception text.
  An `error` step writes one operator note on the receipt channel: the exception class, the file basename and the line
  (constitution VII).

**Limits and failures**

- **FR-016**: Limits are defined in code: at most 20 steps per runbook; a step timeout of at most 5 seconds (default
  2.5 seconds); a step output of at most 2048 bytes (default 512 bytes); a total report of at most 8 KiB; a total
  runbook run of at most 30 seconds. Configuration MAY lower each value and MUST NOT raise it (constitution VI).
- **FR-017**: A step that throws is reported as `error`, and the other steps still run. A step that returns after its
  timeout is reported as `timeout`, and its text is discarded.
- **FR-018**: Step names MUST be unique within a runbook and match `^[a-z0-9][a-z0-9_-]{0,63}$`.

**Enablement and fingerprint**

- **FR-019**: `runbook.<name>` is disabled until `tools.runbook.<name>.enabled` is `true`, and it is hidden from callers
  that do not list it (spec 007, US3). Its tool entry accepts only the keys of spec 007 (`enabled`, `class`, `gate`,
  `fingerprint`, `contract`).
- **FR-020**: The runbook fingerprint is the sha256 of the canonical JSON of the runbook name, its description, and for
  each step its name, `readOnly`, timeout and output size, in declared order. Any change to these changes the
  fingerprint. The approved value is refreshed with `gate:fingerprint`, as for other tools (spec 002, Amendments).

**Validation**

- **FR-021**: `gate:check` MUST report, per runbook: the class exists and implements `Runbook`; the step count is 1 to 20;
  step names are valid and unique; every step is declared read-only; every limit is at or below its cap; the
  fingerprint matches the approved value. Each failure names the runbook key and the rule.
- **FR-022**: Every runbook execution (MCP call) writes a receipt (constitution IV). The receipt holds the runbook name,
  the status count per status, the decision and the rule hashes. It MUST NOT hold any step text.

## Key Entities

- **Runbook**: a named, ordered list of steps in the host application, with a description.
- **Step**: a name, a declared `readOnly` flag, a timeout, an output size, and a `run(RunbookContext)` method that returns
  a status and a text.
- **RunbookContext**: the fixed set of read checks in FR-004. It is the only service a step receives.
- **Report**: the rows of one run, with the total size and the number of dropped rows.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In the test suite, the public method set of `RunbookContext` equals the list in FR-004 (100% of runs).
- **SC-002**: In the test suite, no seeded environment value, config value or database value appears in any console or MCP
  output (0 cases).
- **SC-003**: A runbook with one step that is not declared read-only fails `gate:check` in 100% of tests and is refused
  on every MCP call.
- **SC-004**: A canary value placed in a step's text blocks the MCP response in 100% of tests.
- **SC-005**: For the same environment, the console statuses and the MCP row statuses are equal in 100% of tests.
- **SC-006**: Any change to a step's name, `readOnly`, timeout or output size changes the fingerprint in 100% of tests and
  leaves it unchanged when nothing changed.

## Out of Scope

- **Any write**: database writes, cache writes, file writes, queue dispatch, mail, notifications, and any method that
  changes state (constitution II; the MVP is read-only). Write flows are described as blocked in spec 019.
- **HTTP calls to external services** from a step or from the package (constitution: outbound HTTP needs a host
  allowlist and is a separate source spec). A step may make such a call in host code; the package does not mediate or
  check it.
- **Queue dispatch and job execution**, including a "test job" for queue health.
- **Preemptive timeouts**: the package does not kill a step (see Assumptions).
- **Scheduling, retries and alerting**: a runbook runs when asked, once.
- **A runbook UI, a web page, or stored history of runs**: the report is printed or returned.
- **Runbook-defined contracts or classifications**: the report shape is fixed by this spec.
- **Using a model to choose or write steps** (constitution V).

## Constitution Check

- **I (privacy)**: no value leaves a step; the context returns booleans and integers; output runs through the text stages.
- **II (read-only)**: no write method is reachable from a step; enforced by the context surface, the `readOnly` check and a
  source scan test. The package does not add any tool that writes.
- **III (deny by default)**: the tool is disabled until enabled; hidden tools answer as unknown tools.
- **IV (receipts)**: every MCP call writes a receipt; a console run is not a tool call and writes none (FR-015).
- **V (code decides)**: all checks in the runner and the firewall are deterministic code.
- **VI (config narrows)**: all limits are caps in code; configuration only lowers them; `runbooks` cannot widen them.
- **VII (quiet to the model)**: a failed step returns a generic text; the exception goes to the operator note only.
- **VIII (production)**: MCP access stays on the authenticated HTTP path; no new access path is added.
- **IX (test-first)**: each FR above is to get a test before its implementation (planning and tasks, not this spec).
- **X (dependencies)**: no new dependency. The checks use the Laravel framework (Cache, Queue, DB, Config, Route, Artisan).

**Amendment needed?** No constitution amendment is needed. Two spec changes are needed when this spec is planned:
spec 007 must add `runbooks` to its list of valid top-level configuration keys (unknown keys are `config.invalid`), and
spec 002's source list must add the runbook source with its classification (this spec states it: `internal`, ceiling
`internal`).

## Assumptions

- Runbooks are written by developers of the host application. The package does not trust a runbook's output for
  secrets: the text stages (FR-010) are the safety net, and the context surface (FR-004) is the main control.
- PHP cannot interrupt a blocking call in this design without `pcntl`, which is not always available. The timeout is
  enforced by measuring: a step that returns late is reported as `timeout` and its text is discarded. The real bound
  comes from the connection timeouts of the framework (Redis, database, HTTP client). This is the main open limit; see
  the owner question in the report.
- "Read-only" is a property of the package, not of arbitrary host code. A step can still call a write in its own PHP
  code, for example through a facade. The package's guarantee is that its own surface offers no write, that the
  declaration is checked, and that the report never shows values. The developer is responsible for the step code.
- A runbook runs in the same process and under the same configuration as the application.
- Migration status uses the framework migrator's read of the migrations table; queue reachability uses a read-only
  connection check, not a job.
- The default limits in FR-016 are proposals for the owner to confirm before planning.
- `gate:runbook` is an operator command; it is not part of the MCP stdio protocol path, so its output may go to stdout.

## Amendments

- 2026-11-01 (owner decision 1, timeouts): PHP cannot preempt a blocking step without `pcntl`. A step that blocks is reported as `timeout` when it returns, and only the connection timeouts bound the run. ACCEPTED.
- 2026-11-01 (owner decision 2, console): `gate:runbook` runs even when `runbook.<name>` is disabled (FR-013). ACCEPTED.
- 2026-11-01 (owner decision 3, limits): the default limits in FR-016 (20 steps, 5 s per step, 2048 bytes per step output, 8 KiB report, 30 s per run) are the code caps. ACCEPTED.
- 2026-11-01 (owner decision 4, receipts): the console run writes no receipt because it is not a tool call (FR-015, constitution IV). ACCEPTED.
- 2026-11-01 (review 018-REV, cross-spec): spec 007 must add `runbooks` to its valid top-level keys, and spec 002 must add the runbook source (`internal`, ceiling `internal`). Tracked as tasks in `plan.md`; not yet applied to specs 002 or 007.
- 2026-11-01 (review 018-RB-FIX, read-only guarantee): runbook steps are trusted host code. `readOnly` is a declaration, not enforcement: a step can write or call out if its code does, and the context surface is read-only only for the package's own methods. A hung step connection is not bounded by the run budget, since the timeout is judged after the step returns. Documented in `docs/configuration.md` (`runbooks`). ACCEPTED.
- 2026-11-01 (review 018-RB-FIX, tool ceiling, T034): a caller granted the tool whose ceiling is below the runbook's is refused (receipt `policy.denied`); a caller not granted it gets `Tool not found`, identical to an unknown name. The code is unchanged; only the T034 expectation is corrected. ACCEPTED.
- 2026-11-01 (review 018-DOC-FIX, accuracy): a call for an unregistered runbook name gets `failed` with reason `source.missing` (a caller who cannot reach the ceiling is refused first, by policy). `gate:check` prints one or two lines per runbook: a second fingerprint line when the runbook has a tool entry. The receipt `status_counts` include the `warn` report note when rows were dropped. No behaviour change except the validator message, which now prints the exception class without its NUL byte or path. ACCEPTED.
