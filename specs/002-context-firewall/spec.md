# Feature Specification: Context Firewall

**Feature Branch**: `002-context-firewall`

**Created**: 2026-10-30

**Status**: Draft

**Input**: Owner brainstorm (roadmap milestone M3): every tool response passes a firewall before it
leaves the server. Only fields that a contract allows reach the caller; secrets never do; personal data
arrives only in transformed form; text from sources is marked untrusted; every response is checked for
canary values; volume is limited per call, per session and per day. Builds on spec 001 (server, tool
registry, receipts).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Only contract fields leave the server (Priority: P1)

A developer defines a contract for a model: which fields a tool may return and in what form. A tool
response contains exactly those fields. A field that is not in the contract never appears, even if the
data source returns it. A field with no classification in the catalog is dropped and never shown.

**Why this priority**: This is the core privacy promise. Everything else builds on it.

**Independent Test**: Give a tool a contract with two of four fields and a source row with five values,
one of them unclassified; confirm only the two contract fields appear.

**Acceptance Scenarios**:

1. **Given** a contract that lists `status` and `total`, **When** the tool returns a row with `status`,
   `total` and `notes`, **Then** the response contains `status` and `total` only.
2. **Given** a column with no classification in the catalog, **When** a contract lists it, **Then** the
   field is dropped and the receipt records it under `dropped` with reason `field.unclassified`.
3. **Given** a contract that refers to a column missing from the catalog, **When** the server loads the
   contract, **Then** the contract check fails and the server does not start with that tool enabled.

---

### User Story 2 - Secret and personal values never leave raw (Priority: P1)

Fields classified `secret` never appear in any response, in any form. Fields classified `personal`
appear only as a pseudonym, an aggregate count or a bucket. The caller never sees a raw personal value.

**Why this priority**: Constitution principle I. A single raw personal value breaks the product promise.

**Independent Test**: Seed a row with an email, a password hash and a numeric total; request it through a
contract that lists all three; confirm the password is absent, the email is a pseudonym and the total is
a bucket.

**Acceptance Scenarios**:

1. **Given** a contract that lists a `secret` column, **When** the contract is loaded, **Then** loading
   fails with a message naming the field and the rule.
2. **Given** a `personal` column listed in a contract with no transformation, **When** the contract is
   loaded, **Then** loading fails: a personal field needs a pseudonym, aggregate or bucket.
3. **Given** a `personal` column with a pseudonym transformation, **When** a tool returns it, **Then**
   the response contains a pseudonym and never the raw value.

---

### User Story 3 - Pseudonyms are stable within their scope and resolve only on the server (Priority: P1)

The same record gets the same pseudonym for the whole session by default, so the agent can connect
records within one investigation. A tool may ask for a longer scope (one or seven days). A pseudonym
can be sent back as an argument and is resolved on the server. An unknown or expired pseudonym is
refused, so the caller cannot enumerate records.

**Why this priority**: Pseudonyms are the only way the model can refer to a record without seeing it.

**Independent Test**: Call a tool twice for the same record in one session and once in a new session;
confirm the pseudonym matches in the same session and differs in the new one. Send an unknown pseudonym
as an argument and confirm refusal.

**Acceptance Scenarios**:

1. **Given** one session, **When** the same record is returned twice, **Then** both responses carry the
   same pseudonym.
2. **Given** a tool configured with a seven-day scope, **When** the same record is returned in two
   sessions on different days within seven days, **Then** the pseudonym matches.
3. **Given** a pseudonym that was never issued in this scope, **When** it is sent as an argument,
   **Then** the response is `pseudonym.unknown`, and the receipt records no record identifiers.
4. **Given** a seven-day scope has expired, **When** the old pseudonym is sent, **Then** it is refused as
   unknown.

---

### User Story 4 - Related records are limited to one level (Priority: P2)

A contract may include a related record only through that record's own contract. The related contract
cannot include further relations. The caller's ceiling applies to the related fields too.

**Why this priority**: Without a depth limit, a chain of relations can assemble a personal profile.

**Independent Test**: An order contract includes a customer through the customer contract; the customer
contract includes an address relation; loading the order contract fails with a depth error.

**Acceptance Scenarios**:

1. **Given** an order contract with a relation to a customer contract, **When** a tool returns an order,
   **Then** the customer fields follow the customer contract.
2. **Given** a related contract that has its own relation, **When** the server loads it, **Then** loading
   fails with a depth error.
3. **Given** a caller with ceiling `internal`, **When** a related field is `personal`, **Then** it is shown
   as a pseudonym only, the same rule as for direct fields.

---

### User Story 5 - Canary values block a response (Priority: P2)

Configured canary values (fake emails and tokens placed in documentation, examples and configuration)
are searched for in every response. A match blocks the response and records a `canary.hit` event. This
proves at runtime that the firewall is working on real content.

**Why this priority**: A test suite cannot prove that real data is filtered; a canary can.

**Independent Test**: Place a canary value in a fixture the tool returns; call the tool; confirm the
response is blocked with the generic failure, and the receipt and log record `canary.hit`.

**Acceptance Scenarios**:

1. **Given** no canary in a response, **When** the tool is called, **Then** the response is returned.
2. **Given** a canary value in a response, **When** the tool is called, **Then** the response is blocked
   with `Request failed. Reference: r_...` and the receipt records `canary.hit`.
3. **Given** a canary value, **When** it appears in an argument, **Then** the call is refused and the
   event is recorded.

---

### User Story 6 - Text from sources is marked untrusted and injection phrases are flagged (Priority: P2)

Text returned from a source is wrapped as untrusted content with its source name. Invisible characters
are removed. Phrases that try to give instructions to the model are flagged in the response metadata and
in the receipt. The response is not blocked for a flag alone.

**Why this priority**: Documentation and comments can contain instructions meant for the model. Marking
them keeps the model from treating them as commands.

**Independent Test**: A fixture text contains a zero-width character and the phrase "ignore previous
instructions"; the response removes the character, wraps the text and carries a flag.

**Acceptance Scenarios**:

1. **Given** text with zero-width characters, **When** it is returned, **Then** the characters are absent.
2. **Given** text from a source, **When** it is returned, **Then** it is wrapped with `untrusted: true`
   and the source name.
3. **Given** text containing an injection phrase, **When** it is returned, **Then** the response carries
   the flag `injection.phrase` and the receipt records it; the response is not blocked.

---

### User Story 7 - Volume is limited per call, per session and per day (Priority: P2)

Each call returns at most 16 KiB by default. A session may return at most 400 KiB. A token may return at
most 5 MiB per UTC day. Each token may make at most 60 calls per minute. The configuration may raise each
limit, but never above a hard cap in code (64 KiB, 2 MiB, 20 MiB, 300 calls). A response that exceeds a
per-call limit is truncated and says so; a session or daily limit refuses the call.

**Why this priority**: Gradual extraction of a table through many small calls is the main remaining risk
once fields are filtered.

**Independent Test**: Request a large response; confirm truncation with a marker. Make calls until the
session limit is reached; confirm refusal `budget.session`. Exceed 60 calls in a minute; confirm
`rate.limited` with a retry time.

**Acceptance Scenarios**:

1. **Given** a response over 16 KiB, **When** it is returned, **Then** it is truncated, marked
   `truncated: true`, and the receipt records the truncation.
2. **Given** a session that has used its 400 KiB, **When** another call is made, **Then** it is refused
   with `budget.session`.
3. **Given** a token that has used 5 MiB today, **When** another call is made, **Then** it is refused with
   `budget.daily` until 00:00 UTC.
4. **Given** 60 calls in a minute from one token, **When** the 61st call is made, **Then** it is refused
   with `rate.limited` and a retry time.
5. **Given** a configuration value above a hard cap, **When** the check runs, **Then** it fails with the
   name of the setting.

---

### User Story 8 - Receipts show which rules were in force (Priority: P3)

Each receipt records short hashes of the tool description, the contract and the classification catalog.
An auditor can match a past response to the rules active on that day.

**Why this priority**: Required for the audit questions (what did the model see last month), but usable
only after the other stories work.

**Independent Test**: Change a contract and call the tool; confirm the receipt hash changes. Confirm the
hash does not change when nothing changed.

**Acceptance Scenarios**:

1. **Given** a tool call, **When** its receipt is written, **Then** the receipt contains `rules.tool`,
   `rules.contract` and `rules.classification`.
2. **Given** a classification change, **When** the next call is made, **Then** `rules.classification`
   differs from the previous receipt.
3. **Given** no changes, **When** two calls are made, **Then** their rule hashes are identical.

---

### Edge Cases

- A pseudonym key cannot be generated (no entropy): the call fails (`Request failed. Reference: r_...`) and the failure is recorded; no
  unkeyed pseudonym is ever issued.
- Two records produce the same short pseudonym: the pseudonym is extended by one character, and the
  mapping remains unique within the scope.
- Redaction throws an exception on one field: that field is dropped, the rest of the response is
  returned, and the receipt records `redaction.failed`.
- A canary value is split by invisible characters: normalisation runs before the canary check, so the
  canary is still detected.
- A response has no allowed fields after projection: an empty object is returned, not an error.
- A tool returns a value that is an array of records: each record is projected independently.
- The daily budget resets at 00:00 UTC; a call at 23:59:59 counts toward the day that is ending.
- The session limit is reset only by starting a new session, never by time.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The classification catalog MUST assign exactly one class (`public`, `internal`,
  `personal`, `secret`) to every column that a contract can reference.
- **FR-002**: A contract MUST select fields and their form. A contract MUST NOT assign a class.
- **FR-003**: A field without a catalog class MUST be dropped from every response and recorded as
  `field.unclassified`.
- **FR-003a**: A field that the tool returns and the contract does not list MUST be dropped from every
  response and recorded as `field.unlisted`. The record holds the field name only when the name is an identifier
  (letters, digits and underscores, at most 64 characters), at most 50 names per call; any other key, and any name
  past the limit, is recorded once as the placeholder `*`. A key that is not an identifier never reaches a receipt as
  is. An identifier-shaped key cannot be told apart from a column name, so a value of that shape is recorded by name;
  the catalog does not prevent it, and the operator should not key rows by values.
- **FR-004**: A contract that refers to a column without a class, or lists a `secret` field, MUST fail to
  load, and `gate:check` MUST report it.
- **FR-005**: A `secret` field MUST NOT appear in any response, in any form.
- **FR-006**: A `personal` field MUST appear only as a pseudonym, an aggregate or a bucket. A contract that
  lists a `personal` field without one of these MUST fail to load.
- **FR-007**: A field whose class is above the caller's ceiling MUST be dropped and recorded as
  `ceiling.exceeded`.
- **FR-008**: Pseudonyms MUST be derived with a keyed hash over the record identifier and the scope. The
  key MUST be generated per session by default.
- **FR-009**: A tool MAY declare a pseudonym scope of one day or seven days. The key for such a scope MUST
  be stored encrypted and deleted when the scope ends. No scope longer than seven days is accepted.
- **FR-010**: A pseudonym sent as an argument MUST be resolved on the server only. An unknown or expired
  pseudonym MUST be refused with `pseudonym.unknown` and MUST NOT reveal whether a record exists.
- **FR-011**: A relation MUST resolve only through the related record's own contract. Relation depth MUST be
  exactly one. A related contract with a relation MUST fail to load.
- **FR-012**: Related records MUST be loaded in a single query per relation, and the row limit MUST count the
  related records as well.
- **FR-013**: Text from sources MUST have invisible characters removed after compatibility normalisation
  (NFKC) and before redaction.
- **FR-014**: Redaction MUST run with a regular-expression layer over every text field. If redaction fails on
  a field, that field MUST be dropped and `redaction.failed` recorded; the rest of the response is returned.
- **FR-015**: Text from sources MUST be wrapped with `untrusted: true` and its source name.
- **FR-016**: Injection phrases MUST be flagged with `injection.phrase` in the response metadata and the
  receipt. A flag alone MUST NOT block a response.
- **FR-017**: Every response MUST be checked for configured canary values after normalisation. A match MUST
  block the response, return the generic failure with a receipt reference, and record `canary.hit`.
- **FR-018**: Each call MUST be limited to 16 KiB by default. A larger response MUST be truncated and marked
  `truncated: true`.
- **FR-019**: Each session MUST be limited to 400 KiB by default; a call beyond the limit MUST be refused with
  `budget.session`.
- **FR-020**: Each token MUST be limited to 5 MiB per UTC day by default; a call beyond the limit MUST be
  refused with `budget.daily`.
- **FR-021**: Each token MUST be limited to 60 calls per minute by default; a call beyond the limit MUST be
  refused with `rate.limited` and a retry time.
- **FR-022**: The configuration MAY raise the per-call limit up to 64 KiB, the session limit up to 2 MiB, the
  daily limit up to 20 MiB and the call rate up to 300 per minute. Any value above these caps MUST fail
  `gate:check`.
- **FR-023**: Every receipt MUST contain `rules.tool`, `rules.contract` and `rules.classification`: short
  hashes of the tool description, the contract and the classification catalog, computed once per process.
- **FR-024**: Receipts and log entries MUST NOT contain any field value, pseudonym key or canary value.
- **FR-025**: `gate:check` MUST fail when a contract refers to an unclassified column, when a limit exceeds
  its cap, or when a canary value is missing from the configuration while canaries are enabled.

### Key Entities

- **Classification catalog**: Mapping from model column to one class. Single source of truth for the classes.
- **Contract**: A named selection of fields for one model, with the form of each field (raw is never
  allowed for `personal`). Several contracts may exist per model.
- **Pseudonym scope**: A session, one day or seven days. Owns a key and the pseudonyms issued under it.
- **Budget**: Per-call, per-session, per-day and per-minute counters with the configured limits and hard caps.
- **Canary**: A fake value kept in configuration and checked against every response.
- **Rule hash**: A short fingerprint of the tool description, a contract or the catalog.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In 100% of automated privacy tests, no seeded secret value and no raw seeded personal value
  appears in any response.
- **SC-002**: In 100% of tests, a field without a classification is absent from the response and the
  receipt names it.
- **SC-003**: In 100% of tests, a planted canary value blocks the response and records `canary.hit`.
- **SC-004**: In 100% of tests, a response over the per-call limit is truncated and marked; a session or
  daily overrun is refused.
- **SC-005**: `gate:check` reports 100% of the seeded configuration errors in the test suite: unclassified
  columns, secret or unclassified fields in contracts, depth-two relations and values above caps.
- **SC-006**: In a test with 61 calls in one minute, exactly the 61st call is refused.
- **SC-007**: A receipt hash changes in 100% of tests where the catalog or a contract changed, and stays the
  same in 100% of tests where nothing changed.

## Assumptions

- Spec 001 is complete: the server, tool registry, caller binding and receipts exist.
- Data sources (routes, schema, docs, OpenAPI, configuration) are out of scope and come in spec 003. Tests
  use fixture sources.
- The classification catalog is written by a person. Automatic suggestions (`gate:classify`) come in spec 004.
- The regular-expression redaction layer targets common formats (email, phone, card numbers, tokens). It is
  a second layer; the classification catalog stays the primary control.
- Sessions are identified by the caller id, not by the connection. The session budget and the rate counters are
  keyed by caller id, so two clients of the same OS user share them. A stdio process whose cache store is `array` or
  `null` resets its counters on each start.
- The daily budget uses UTC days and is kept in the application cache store.
- Canary values are configured by the operator; the package ships no real secrets, only the check.
- Scope values longer than seven days, and per-field redaction rules beyond the regular-expression layer,
  are out of scope.
- The day and week pseudonym state is read and written without a lock. Two processes sharing the cache store can
  overwrite each other's state; a pseudonym lost that way resolves as `pseudonym.unknown`, which fails closed.
- The in-process session map (session keys and their pseudonyms) grows with the process and is cleared only by a
  restart. Ceiling of operation: one stdio process serves one caller's sessions; a long-lived process that issues a
  very large number of distinct session pseudonyms should be restarted.

## Relation and ceiling rules (decided after User Story 4)

- A field in pseudonym, bucket, date or aggregate form never returns the raw value, so the caller
  ceiling does not drop it. Only `plain` and `text` fields are subject to the ceiling (User Story 4,
  scenario 3).
- The `relation` form is allowed through the classify stage. The class of the foreign key is taken from
  the parent field, so the foreign key is checked like any other column.
- Related rows are read with a base-table query, not Eloquent, so global scopes do not apply. Soft-deleted
  related rows are excluded explicitly (`deleted_at IS NULL`) when the related model uses soft deletes.

## Amendments (review fixes, 2026-11-01)

- `Field::date()` accepts only `year` and `month`.
- `Field::bucket()` needs 1 to 20 strictly ascending breaks, adjacent gap at least 1% of the larger.
- A contract that breaks a load rule is refused per call as `config.invalid`; a column without a catalog class refuses the call.
- The canary check also runs before redaction (stage `canary.pre`) and folds Latin, Cyrillic and Greek look-alikes.
- The tool fingerprint covers name, description and input schema; `gate:fingerprint` output must be refreshed.
- `CeilingCheck` was removed as dead code; the HTTP ceiling travels through the container.

## Amendments (review fixes round 2, 2026-11-01)

- `Field::bucket()`: 1 to 20 strictly ascending breaks; with two or more, every adjacent gap is at least `max(2, 5%)` of the span between the first and last break. Nulls in bucket and date columns pass through as null.
- The canary check runs before redaction, so email-shaped canaries fire. The per-minute limit is checked at call entry (`Budget::admit`), after the config checks, so a `config.invalid` refusal is not counted.
- Budget counters guard against a lost TTL and never go negative. Rows are capped before pseudonymising (2x the per-call limit, at most 10 000) as a work bound; truncation still happens on the final rows.
- Pseudonyms are issued in one batch per call. A scope holds at most 10 000 entries (the key rotates beyond that); one batch of more than 10 000 distinct ids fails closed. Over HTTP session state slides one hour and never lives past creation plus 7 days; an unreadable cached state is replaced; a cache that refuses the map is `pseudonym.keyfail`.
- Call path: `enabled=false` hides every tool (`Tool not found.`, receipt `policy.hidden`). Unexpected exceptions become an `isError` result with a reference, even with `app.debug`. Bad arguments are `isError` results (`Invalid arguments: ...`); JSON-RPC `-32602` is only for a missing name or an unknown tool. A second content block `Notice: ...` reports truncation or flagged text. The server sets `instructions` and advertises only `tools`.
- The tool fingerprint is the sha256 of the canonical JSON of name, description, input schema and annotations.

### Constitution divergences (resolved)

Resolved by constitution 1.1.0 (see `.specify/memory/amendments/2026-11-01-vii-ix.md`):

- Principle VII now says only a metadata-only note (receipt id, exception class, file basename, line) is logged; messages and traces are never logged.
- Principle VII now says the model receives a generic message, plus a receipt identifier for errors; the reason code is in the receipt only.
- Principle IX now says no test run by `composer test` uses the network; the install matrix is its own CI job and its PHPUnit wrapper is opt-in.

## Amendments (batch 3, 2026-11-01)

- Call-path order: hidden or unknown tool, configuration refusal, per-minute admit, canary and contract rules on the
  arguments, schema validation of the arguments exactly as sent (pseudonym strings included), pseudonym resolution
  (`pseudonym.unknown`), policy, ceiling and contract, fetch, firewall, budget. A non-string pseudonym argument is
  `Invalid arguments: ...` (`input.invalid`).
- Extension interfaces `RedactsText`, `IssuesPseudonyms` and `AuthorizesTools` (`ContextGate\Contracts`, `@api`) replace
  subclassing of `Redactor`, `Pseudonymizer` and `ToolPolicy` (now `@internal` for subclassing). An `IssuesPseudonyms`
  implementation is trusted and must never return the raw id.
- Constitution 1.1.0 (principles VII and IX) resolves the divergences listed above; see
  `.specify/memory/amendments/2026-11-01-vii-ix.md`.
