# Feature Specification: Durable Receipt Sinks and Operator Metrics

**Feature Branch**: `016-receipt-sink`

**Created**: 2026-11-01

**Status**: Clarified

**Input**: Owner request: "improvements and features the package needs". Receipts today are one JSON line per call on
a log channel that defaults to stderr. Operators cannot query them from the package, and nothing is kept for a known
time. This spec adds optional durable sinks (a database table with retention, and a structured log format ready for
Loki), a query mode for `gate:audit`, and the metrics an operator dashboard needs. Principle IV still holds: if a
receipt cannot be written, the response is not returned.

## Clarifications

### Session 2026-11-01

- Q: What is the retention default and code cap for stored receipts: 30 days with a 180-day cap, or a longer cap for audit needs? (FR-008) → A: 30 days default, code cap 180 days. Longer audit retention belongs in the log pipeline (e.g. Loki).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Several sinks, one clear rule for "written" (Priority: P1)

An operator configures one or more receipt sinks and marks each as required or best-effort. A call returns its
response only when every required sink accepted the receipt. A best-effort sink that fails does not block the
response, but the failure is counted and noted on a required sink.

**Why this priority**: Principle IV must stay true with more than one sink. Without a rule, either one slow sink blocks
every call or a lost receipt goes unnoticed.

**Independent Test**: Configure the log sink as required and the database sink as best-effort; make the database
fail; the call returns and the log holds the receipt plus a sink-failure note. Make the required sink fail; the call
returns the generic failure and no data.

**Acceptance Scenarios**:

1. **Given** a required sink that accepts the receipt, **When** a call finishes, **Then** the response is returned.
2. **Given** a required sink that fails, **When** a call finishes, **Then** the model receives only the generic
   failure text and none of the tool's output.
3. **Given** a best-effort sink that fails and a required sink that accepts, **When** a call finishes, **Then** the
   response is returned and the required sink holds a metadata-only note naming the failed sink and the receipt id.
4. **Given** a configuration with no required sink, **When** `gate:check` runs or the gate starts, **Then** it fails:
   at least one sink is required.
5. **Given** no sink configuration, **When** the gate runs, **Then** the log channel is the only sink and it is
   required, as today.

---

### User Story 2 - Store receipts in a database table with retention (Priority: P1)

An operator enables the database sink. Each receipt is stored as one row with an expiry time. Expired rows are
pruned on a schedule and are never returned by a query, even before they are pruned.

**Why this priority**: m-seq needs a receipt trail it can query after the log has rotated, and every stored record
needs a retention limit.

**Independent Test**: Store receipts with a short retention; advance the clock past it; a query returns none of them
and pruning deletes them.

**Acceptance Scenarios**:

1. **Given** the database sink, **When** a call writes a receipt, **Then** one row holds the receipt fields and an
   expiry time equal to the write time plus the retention.
2. **Given** a retention above its code cap, **When** `gate:check` runs, **Then** it fails and names the key.
3. **Given** rows past their expiry, **When** pruning runs, **Then** they are deleted and the count is reported, with
   no field of the deleted rows.
4. **Given** rows past their expiry that are not yet pruned, **When** a query runs, **Then** they are not returned.
5. **Given** the database sink is enabled and its table does not exist, **When** `gate:check` runs, **Then** it fails
   and names the table.

---

### User Story 3 - Structured log format for Loki (Priority: P2)

An operator selects the structured log format. Each receipt line has a fixed, versioned set of fields, so a log
pipeline can turn a few low-cardinality fields into labels and keep the rest as line content.

**Why this priority**: The m-seq team runs Grafana and Loki. A stable format is the cheapest way to dashboards without
a new dependency.

**Independent Test**: Write one receipt per reason code; every line parses as JSON, carries the format version, and
uses only the documented field names.

**Acceptance Scenarios**:

1. **Given** the structured format, **When** a receipt is written, **Then** the line is one JSON object with a format
   version and only documented fields.
2. **Given** the documented label fields, **When** they are counted over all reason codes, tools and transports,
   **Then** each label field has a bounded set of values; the caller id and the receipt id are never label fields.
3. **Given** the structured format over stdio, **When** a receipt is written, **Then** nothing is written to stdout.

---

### User Story 4 - Query stored receipts with `gate:audit` (Priority: P2)

An operator asks `gate:audit` for receipts by caller, tool, reason, decision and time window. The command reads the
database sink, prints metadata only, and never prints a value. It can also print counts per reason for a window.

**Why this priority**: Answering "what did token 12 do yesterday" today means searching raw logs.

**Independent Test**: Seed receipts for two callers; query one caller and a window; only that caller's receipts in the
window are printed.

**Acceptance Scenarios**:

1. **Given** stored receipts, **When** `gate:audit` runs with a caller filter and a window, **Then** it prints only the
   matching receipts, newest first, with metadata fields only.
2. **Given** a filter that matches nothing, **When** the query runs, **Then** it prints an empty result and exits 0.
3. **Given** the JSON option, **When** the query runs, **Then** stdout holds only a JSON array.
4. **Given** the database sink is not enabled, **When** a query runs, **Then** it exits non-zero and says that queries
   need the database sink.
5. **Given** any query, **When** it runs, **Then** it writes one metadata-only audit line naming the operator's OS
   user and the filter keys used, not the filter values.
6. **Given** a window wider than the query cap, **When** the query runs, **Then** it is refused and names the cap.

---

### User Story 5 - Operator metrics (Priority: P3)

An operator builds a dashboard from receipts: refusals, canary hits, budget exhaustion, throttled clients, failures
and sink health. The package documents each metric as a count of receipts with given fields, so it can be computed in
the log stack or from the table.

**Why this priority**: Dashboards are useful after the sinks exist; they need nothing new on the call path.

**Independent Test**: Seed one receipt per metric; the documented definition, applied to the seeded set, counts 1 for
each metric.

**Acceptance Scenarios**:

1. **Given** the metric definitions, **When** they are applied to a seeded set of receipts, **Then** each metric gives
   the expected count.
2. **Given** a summary query for a window, **When** `gate:audit` prints it, **Then** it shows the count per metric
   and no caller-level value beyond the caller ids already in receipts.

---

### Edge Cases

- The database is down and the database sink is required: every call returns the generic failure and no data. This
  is intended (principle IV); operators who want availability mark it best-effort.
- A best-effort sink is slow: it has its own timeout within a code cap and counts as failed when it runs out. A slow
  best-effort sink never delays a response beyond that timeout.
- The required log sink is a channel that discards lines (for example a null driver): the package cannot detect this.
  `gate:check` warns when the channel driver is known to discard.
- A receipt id collides with a stored id: the write fails as a sink failure. Receipt ids are long enough that this is
  not expected within the retention window.
- Pruning has not run for days: queries still hide expired rows, and `gate:check` warns when expired rows exist.
- The clock moves backwards: expiry is computed from the write time; a row is never kept past write time plus the
  code cap.
- A preview or simulated receipt: it goes to the same sinks and keeps its `source` marker; metrics exclude it.
- The HTTP refusal throttle writes `http.throttled` receipts with caller `unknown` and no IP: the "throttled clients"
  metric counts these receipts per window; it cannot and does not name clients.
- The database transaction of the host request rolls back: the receipt write uses its own connection or is
  committed outside the host transaction, so a rollback never removes a receipt.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The gate MUST support these sinks: the log channel (as today), a database table, and the structured
  log format on a log channel. Each configured sink is required or best-effort.
- **FR-002**: At least one sink MUST be required. A configuration with none fails `gate:check` and refuses every call
  with `config.invalid`.
- **FR-003**: With no sink configuration, the log channel MUST be the only sink, required.
- **FR-004**: A receipt is "written" to a sink when: for a log sink, the logger accepted the line without an error;
  for the database sink, the row insert completed without an error. The documentation MUST say that a log sink
  cannot confirm durability.
- **FR-005**: A response MUST be returned only when every required sink has written the receipt. If any required sink
  fails, the model receives only the generic failure text and no tool output.
- **FR-006**: A best-effort sink failure MUST NOT block the response. It MUST be recorded as a metadata-only note on
  every required sink: the receipt id, the sink name and the failure class, never the message.
- **FR-007**: Every sink MUST receive the same receipt fields. No sink may hold an argument, a result, a field value, a
  token value, a canary value, a pseudonym key or a client IP.
- **FR-008**: Each stored receipt MUST have an expiry time. The retention default is 30 days and the code cap is 180
  days. Configuration may only lower it. Longer audit retention belongs in the log pipeline (for example Loki), not in
  the stored receipts.
- **FR-009**: Expired receipts MUST NOT be returned by any query, and MUST be deleted by a pruning command that a host
  can schedule.
- **FR-010**: The structured format MUST carry a format version and a documented, fixed set of fields. A field is
  added only with a format version change.
- **FR-011**: The documented label fields MUST have bounded values: decision, reason, transport, tool name, source
  marker. Caller id and receipt id MUST NOT be label fields.
- **FR-012**: `gate:audit` MUST offer a query mode over the database sink, with filters for caller, tool, reason,
  decision and time window, a result limit within a code cap, and a JSON option.
- **FR-013**: The query window and the result limit MUST each have a code cap. A request above a cap is refused and
  names the cap.
- **FR-014**: The query mode MUST print metadata only, and MUST write one audit line naming the OS user and the
  filter keys used.
- **FR-015**: `gate:audit` MUST offer a summary for a window: the count of each metric in FR-016.
- **FR-016**: The documentation MUST define these metrics as receipt counts: calls by decision; refusals by reason;
  canary hits; budget exhaustion (session and daily); rate-limited calls; throttled clients (`http.throttled`
  receipts per window); handler and transform failures; pin mismatches; truncated responses; best-effort sink
  failures. Preview and simulated receipts are excluded.
- **FR-017**: Receipt ids MUST be unique within the retention cap. The default is at least 96 random bits.
- **FR-018**: `gate:check` MUST fail when the database sink is enabled and its table is missing, and MUST warn when
  expired rows exist or the required log channel is known to discard lines.
- **FR-019**: A receipt write MUST NOT depend on the host request's database transaction.

### Key Entities

- **Receipt**: the existing metadata-only record (id, time, caller, tool, decision, reason, rule hashes, dropped field
  names, flags, refusal step, source), plus a format version and an expiry time when stored.
- **Sink**: where a receipt is written; has a name, a kind (log, database, structured log) and a mode (required or
  best-effort).
- **Sink-failure note**: receipt id, sink name, failure class.
- **Retention**: the time a stored receipt lives, within a code cap.
- **Metric**: a named count of receipts that match given field values over a window.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: When a required sink fails, a response with tool output is returned in 0 cases.
- **SC-002**: When only a best-effort sink fails, 100% of responses are returned and each failure has a note on a
  required sink.
- **SC-003**: No stored or logged receipt contains a seeded sentinel value, canary value or client IP (0 cases).
- **SC-004**: No query returns a receipt past its expiry (0 cases), and pruning removes 100% of expired rows.
- **SC-005**: Each documented metric, applied to a seeded receipt set, gives the expected count (100%).
- **SC-006**: Every structured line parses and uses only documented fields (100% of reason codes).

## Out of Scope

- **A sink that sends receipts over the network from the package** (a push to Loki, a webhook, a metrics endpoint):
  principle X, Minimal Dependencies. Shipping logs to Loki stays with the host's log collector.
- **Receipts that keep values, arguments or results for debugging** (principle IV: receipts never hold field values;
  principle I).
- **Unlimited retention or a retention set above the code cap** (principle VI). Audit retention beyond 180 days is
  the host's log pipeline (for example Loki), not this sink.
- **Returning a response when all required sinks fail** ("fail open" for availability): principle IV.
- **A Grafana dashboard file**: the spec defines metrics, not a dashboard; a dashboard depends on the host's stack.
- **Querying receipts through an MCP tool** (principle III and II): an assistant does not read the audit trail.
- **Exception messages in sink-failure notes** (principle VII).

## Assumptions

- The database sink uses the host's database through the framework; no new runtime dependency. Its table ships as a
  publishable migration, like the token tables.
- Defaults: retention 30 days (default), 180 days (code cap), decided 2026-11-01; query window cap 31 days; result limit default 100, cap 1000;
  best-effort sink timeout cap 1 second.
- The structured format replaces the current line format on the log sink when selected; with no format selected, the
  current line stays. Pre-010, no compatibility period is needed.
- The pruning command is scheduled by the host (for example daily); the package does not schedule it on its own.
- The classification hash in receipts follows the merged catalog of spec 015, and the receipt gains a kind field for
  resources and prompts in spec 017.
- Spec 011's security scope grows to include the sink write path, because it decides whether a response is returned.

## Amendments (owner decisions, 2026-11-01)

- 2026-11-01: the `kind` field of the receipt uses format version v2 (016 plan's structured v2).
- 2026-11-01: a best-effort timeout does not interrupt a synchronous PHP call; the time limit is checked after the call returns.
- 2026-11-01: PROPOSAL, not yet accepted: a receipt counts as written only when a REQUIRED sink accepts it; best-effort sinks do not count (016 T013/T022).
