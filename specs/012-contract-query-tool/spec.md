# Feature Specification: Contract Query Tool with Pagination

**Feature Branch**: `012-contract-query-tool`

**Created**: 2026-11-01

**Status**: Clarified

**Input**: Owner request: "features the package needs: a query tool generated from a contract with pagination;
aggregates with a minimum group size; row-level scoping per caller". This spec covers the first item: a query tool
generated from a contract, with filters, sorting, a hard row limit and cursor pagination. Aggregates are spec 013;
row scopes are spec 014.

## Context

Today every MCP tool is a class the host writes. Each class decides its own arguments and query. The firewall then
classifies, projects, transforms and budgets the rows. When a result is larger than the per-call limit, the model
gets the rows that fit and `Notice: result truncated to the per-call limit; narrow the request.` It cannot ask for
the rest.

The first consumer, the m-seq service (sport entries and qualification), needs the same kind of tool many times:
"list entries for this event", "list athletes with this qualification status". Support staff, product owners,
analysts and developers ask these questions through an AI assistant. Writing one class per question is slow, and
every hand-written filter is a new place where a raw value could leak.

This feature lets the host declare a query tool from a contract. The package builds the tool's arguments from the
contract and refuses any filter, sort or page that could reveal a value above the caller's ceiling.

## Clarifications

### Session 2026-11-01

- Q: Must every query carry at least one filter, or may a caller page through all rows of a contract with no filter, bounded only by the per-session and per-day byte limits? → A: Every query MUST carry at least one filter by default. A contract may explicitly opt in to unfiltered listing (bounded by the page size, row cap and byte budgets). Unfiltered requests to a contract without the opt-in are refused as invalid arguments.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Declare a query tool from a contract (Priority: P1)

A developer declares a query tool for an existing contract. The declaration names the fields that may be filtered,
the fields that may be sorted, and a page size. No tool class is written. The package builds the tool's name,
description and input schema from the contract and the declaration. The tool is enabled, pinned and allowed per
caller exactly like any other tool.

**Why this priority**: Without the generated tool, nothing else in this spec exists. It also removes the most
common source of hand-written query code.

**Independent Test**: Declare a query tool on a fixture contract with one public filter and one sort field. Pin
it with `gate:fingerprint`, allow it for a caller, call it with a filter. The rows match the filter and pass the
same firewall stages as a hand-written tool.

**Acceptance Scenarios**:

1. **Given** a query declaration on a valid contract, **When** the tool list is requested by an allowed caller,
   **Then** the tool appears with a schema whose filter and sort arguments are exactly the declared ones.
2. **Given** a declaration that lists a filter on a field the contract does not list, **When** `gate:check` runs,
   **Then** it fails and names the tool and the field.
3. **Given** a declared query tool with no fingerprint, or a fingerprint that does not match, **When** a caller
   lists or calls it, **Then** it is hidden exactly like a pin-mismatched hand-written tool.
4. **Given** the contract behind a pinned query tool gains a filterable field, **When** the server starts, **Then**
   the generated schema changes, the fingerprint no longer matches and the tool is hidden until it is re-pinned.

---

### User Story 2 - Filter only in forms that cannot reveal a raw value (Priority: P1)

An analyst asks the assistant for "entries of athlete ATH_7f3a in the 2026 season with status confirmed". The
assistant calls the query tool with an equality filter on the pseudonym, a period filter on a date field and an
exact match on a plain status field. The package accepts each filter only in the form its field allows. A filter
that could reveal a raw value above the caller's ceiling is refused.

**Why this priority**: A filter is a question about a value. A filter that matches rows by a hidden value tells
the caller that value, even if the value is never returned. This is the security core of the feature.

**Independent Test**: Seed known personal values and canaries. Run every filter form against every field form and
class. Only the allowed combinations in FR-006 return rows; every other combination is refused, and no seeded value
appears in any response or receipt.

**Acceptance Scenarios**:

1. **Given** a pseudonym field, **When** the caller filters by a pseudonym it was issued, **Then** the rows of that
   record are returned.
2. **Given** a pseudonym field, **When** the caller filters by an unknown, expired or foreign pseudonym, **Then**
   the call is refused exactly like an unknown pseudonym argument today (`pseudonym.unknown`).
3. **Given** a bucket field, **When** the caller filters by one or more whole buckets, **Then** the rows in those
   buckets are returned.
4. **Given** a bucket field, **When** the caller sends a bound that is not a declared break, **Then** the call is
   refused as invalid arguments.
5. **Given** a plain internal field and a caller whose ceiling is public, **When** the caller filters on it,
   **Then** the call is refused and the receipt records `filter.denied`.
6. **Given** a text field, **When** the caller sends a filter on it, **Then** the call is refused as invalid
   arguments (text fields are never filterable).
7. **Given** a contract whose declaration has no opt-in to unfiltered listing, **When** the caller calls the tool
   with no filter, **Then** the call is refused as invalid arguments and no row is returned.
8. **Given** a contract whose declaration opts in to unfiltered listing, **When** the caller calls the tool with no
   filter, **Then** the first page is returned, bounded by the page size, the row cap and the byte budgets.

---

### User Story 3 - Fetch the next page after truncation (Priority: P1)

The assistant asks for all confirmed entries of an event. The result is larger than one page or than the per-call
byte limit. The response returns the rows that fit and an opaque cursor. The assistant calls the tool again with
the same arguments and the cursor, and gets the next rows, with no row repeated or skipped.

**Why this priority**: Today a truncated result is a dead end. Pagination is the second half of the owner's
request and the reason the model can answer "how many" questions over a list without guessing.

**Independent Test**: Seed 250 rows, page size 100, call with no cursor (with a filter matching all 250 rows, or on a
contract that opts in to unfiltered listing), then follow each cursor. The union of the
pages equals the full filtered set, in order, with no duplicate, and the last page carries no cursor.

**Acceptance Scenarios**:

1. **Given** more rows than the page size, **When** the tool is called, **Then** the response holds one page of
   rows, a cursor, and a `Notice:` block saying more rows are available and how to request them.
2. **Given** a page truncated by the per-call byte limit before it reached the page size, **When** the tool is
   called with the returned cursor, **Then** the next page starts at the first row that was not served.
3. **Given** the last page, **When** it is returned, **Then** it carries no cursor and no "more rows" notice.
4. **Given** a cursor, **When** the caller sends it with different filters or sort, **Then** the call is refused
   as invalid arguments and no row is returned.

---

### User Story 4 - Cursors cannot be replayed, shared or decoded (Priority: P2)

A cursor is valid only for the caller, tool, contract rules and filter set that produced it, and only for a short
time. It carries no value. A cursor from another caller, an expired cursor or a tampered cursor gives the same
response as a cursor that never existed.

**Why this priority**: A cursor is state the model holds. If it encoded a raw sort value, or worked for another
caller, it would become a side channel.

**Independent Test**: Issue a cursor to caller A. Present it as caller B, after its expiry, after a contract
change, and with one character changed. Each call gets the identical response; the receipts record the real reason.

**Acceptance Scenarios**:

1. **Given** a cursor issued to caller A, **When** caller B presents it, **Then** the response is identical to
   the response for an unknown cursor and the receipt records `cursor.invalid`.
2. **Given** a cursor older than its time-to-live, **When** it is presented, **Then** the response is identical
   to the response for an unknown cursor.
3. **Given** the contract, classification or query declaration changed after the cursor was issued, **When** the
   cursor is presented, **Then** it is refused as `cursor.invalid`.
4. **Given** any issued cursor, **When** it is inspected, **Then** it contains no field value, record key, sort
   value or offset that a reader could decode.

---

### User Story 5 - Pages count against budgets and are fully receipted (Priority: P2)

An operator reads the receipts of a paginated query. Each page is one call: it counts against the per-minute,
per-session and per-day limits. Each receipt records which fields were filtered and with which operator, the sort
field, the page number and whether a cursor was issued. It records no filter value.

**Why this priority**: Pagination must not become a way around the budgets, and operators must be able to see how
a caller walked a table without seeing what it asked for.

**Independent Test**: Lower the session byte limit, follow cursors until the limit refuses a page, and read the
receipts. The refused page is `budget.session`; no receipt contains a sentinel filter value.

**Acceptance Scenarios**:

1. **Given** the session byte limit is used up, **When** a cursor is presented, **Then** the call is refused with
   `budget.session` like any other call, and the cursor is not consumed.
2. **Given** a call with a sentinel value in a filter, **When** the receipt is written, **Then** it holds the field
   name and operator and not the sentinel.
3. **Given** a page that contains a canary value, **When** the response is built, **Then** it is blocked with
   `canary.hit` and no cursor is returned.

---

### Edge Cases

- Rows change between pages (insert, update, delete). Pagination continues after the last served row in the sort
  order; a row inserted before the cursor position is not shown, and a deleted row is skipped. No row is served
  twice within one walk. This is documented, not prevented.
- Two rows have the same sort value. The order is made total by a hidden tie-breaker that never reaches the
  response or the cursor.
- A call carries no filter on a contract without the opt-in: refused as invalid arguments (FR-026). A call whose only
  filters are all rejected as malformed is refused for those, not treated as unfiltered.
- A filter matches nothing. The response is an empty list with no cursor; it is not a refusal.
- The cursor store is unavailable or refuses to write. The page is returned without a cursor and with a notice
  that the result is incomplete; the call is not failed and no cursor is invented. Presenting a cursor while the
  store is unreadable is `cursor.invalid`.
- A page size argument above the configured page size is clamped to it, not refused.
- A cursor is presented after the caller's ceiling or row scope (spec 014) changed. The cursor is bound to both,
  so it is refused.
- An `in` filter with more values than its cap is refused as invalid arguments.
- A pseudonym filter on a `day` or `week` scoped pseudonym presented after the scope rotated: `pseudonym.unknown`.
- The per-call row cap and the page size disagree: the smaller one wins.
- The model sends the cursor without the original filters: refused as invalid arguments (the filter set is part
  of the binding).

## Requirements *(mandatory)*

### Functional Requirements

**Declaration and generation**

- **FR-001**: A host MUST be able to declare a query tool from a contract without writing a tool class. The
  declaration names the filterable fields with their allowed operators, the sortable fields, and a page size, and MAY
  opt in to unfiltered listing (FR-026).
- **FR-002**: The package MUST build the tool's name, description, input schema and annotations only from the
  contract and the declaration. The annotations are read-only, non-destructive, idempotent and closed-world.
- **FR-003**: A generated tool MUST follow every rule of a hand-written tool: it is off until enabled, needs a gate
  ability, is allowed per caller, and is pinned by a fingerprint over what `tools/list` shows. A schema change
  hides the tool until it is re-pinned.
- **FR-004**: A declaration that names a field the contract does not list, an operator the field's form does not
  allow (FR-006), or a sort field that FR-008 forbids MUST make the tool `config.invalid`, and `gate:check` MUST
  fail and name the tool and field.
- **FR-005**: The generated tool MUST return rows only through the existing firewall stages, in their existing
  order. It MUST NOT bypass classify, projection, relations, transform, normalise, redact, canary, quarantine or
  budget.

**Filters**

- **FR-006**: A filter MUST be accepted only in these forms:
  - plain field of class `public` or `internal`, at or below the caller's ceiling: equality, membership in a list
    (`in`, at most 20 values), and inclusive ranges;
  - pseudonym field: equality with one pseudonym issued to this caller in this field's scope, resolved on the
    server exactly like a pseudonym argument today;
  - bucket field: membership in one or more whole buckets, named by the bucket's declared bounds;
  - date field: equality or range on periods at the field's declared precision (year or month), never finer.
  Every other combination MUST be refused.
- **FR-007**: Text, aggregate and relation fields MUST NOT be filterable. Text is excluded because matching on the
  stored text would reveal content that redaction removed.
- **FR-008**: A sort MUST be allowed only on declared plain fields of class `public` or `internal`, at or below the
  caller's ceiling. Sorting on pseudonym, bucket, date or any field derived from a `personal` column MUST be
  refused, because the order would reveal the raw values.
- **FR-009**: A filter or sort on a field above the caller's ceiling MUST be refused with the generic refusal, and
  the receipt MUST record `filter.denied`. Malformed filters (wrong type, unknown operator, bound that is not a
  declared break, too many `in` values) MUST be `input.invalid`.
- **FR-010**: Filters MUST be combined with AND only. No OR, negation, pattern match or substring match is
  available in this spec.
- **FR-011**: Filter values MUST pass the canary check on arguments before any query runs, as all arguments do
  today.

**Paging and limits**

- **FR-012**: A page MUST hold at most the page size. The page size has a code cap of 200 rows and a default of 50;
  configuration and the declaration may only lower it. A requested page size above it is clamped.
- **FR-013**: The order of rows MUST be total and stable: the requested sort, then a hidden tie-breaker. The
  tie-breaker MUST never appear in a response or a cursor.
- **FR-014**: When more rows exist after the last served row, the response MUST carry a cursor in the structured
  content and a `Notice:` block saying more rows are available and that the same arguments plus the cursor return
  the next page. When the per-call byte limit truncates a page, the cursor MUST continue from the first unserved row.
- **FR-015**: The last page MUST carry no cursor.

**Cursors**

- **FR-016**: A cursor MUST be an opaque random identifier. It MUST NOT encode a field value, a record key, a sort
  value or an offset. The position it stands for MUST be held on the server only.
- **FR-017**: A cursor MUST be bound to the caller, the tool, the rule hashes in force (tool, contract,
  classification), the caller's ceiling, the row scope of spec 014 when present, and the exact filter, sort and page
  size arguments. A cursor presented with any of these different MUST be refused.
- **FR-018**: A cursor MUST expire. The time-to-live has a default of 15 minutes and a code cap of 60 minutes;
  configuration may only lower it. The server-side state MUST be stored with that time-to-live.
- **FR-019**: An unknown, expired, foreign, tampered or mismatched cursor MUST produce one identical response
  (`Invalid arguments for tool [<name>].`) and the receipt MUST record `cursor.invalid`.
- **FR-020**: A cursor MUST be consumed only when its page is served. A page refused by a budget, a canary or a
  failure leaves the cursor usable until its expiry.
- **FR-021**: The number of live cursors per caller MUST be capped in code (100). Issuing one more MUST retire the
  oldest.

**Budgets, receipts, canaries**

- **FR-022**: Each page MUST be one call for the per-minute limit and MUST count its bytes against the per-session
  and per-day limits. There is no free continuation.
- **FR-023**: A receipt for a query call MUST record the filtered field names with their operators, the sort field,
  whether a cursor was presented, whether one was issued, and the page number in the walk. It MUST NOT record a
  filter value, a cursor identifier in clear, or a sort value. A short hash of the cursor identifier MAY be
  recorded so operators can link pages of one walk. The receipt MUST record whether the call was unfiltered.
- **FR-024**: A canary found in a page MUST block the whole page, and no cursor MUST be issued with it.
- **FR-026**: Every query MUST carry at least one valid filter by default. A declaration MAY explicitly opt in to
  unfiltered listing for its contract. A call with no filter to a contract without the opt-in MUST be refused as
  `input.invalid`. An unfiltered call to an opted-in contract stays bounded by the page size, the per-call row cap, and
  the per-call, per-session and per-day byte budgets. The opt-in is part of the declaration and so of the rule hash
  and the fingerprint. `gate:check` MUST list each query tool that opts in.
- **FR-025**: Failures MUST stay quiet to the model (constitution VII): every refusal uses the existing generic
  texts, and reason codes appear only in receipts.

### Key Entities

- **Query declaration**: the host's statement for one contract: filterable fields with operators, sortable fields,
  page size, and an optional opt-in to unfiltered listing (off by default). Hashed into the tool's rule hash.
- **Generated query tool**: a tool built from a contract and its declaration. It has a name, description, input
  schema and fingerprint like any tool.
- **Filter**: field name, operator, and an argument value. Only the field name and operator are ever recorded.
- **Cursor**: an opaque identifier for a server-side position in one walk. Bound to caller, tool, rules, ceiling,
  scope and arguments. Has a time-to-live.
- **Walk**: the sequence of pages one caller reads by following cursors from a first call.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A developer exposes a new list question over an existing contract with a declaration only, with no
  new tool class, in under 15 minutes.
- **SC-002**: Following cursors over a seeded set returns every matching row exactly once, in order, in 100% of
  test walks with an unchanged data set.
- **SC-003**: In the privacy suite, no seeded personal value, canary or filter sentinel appears in any response,
  cursor or receipt (0 occurrences).
- **SC-004**: Every filter and sort combination outside FR-006 and FR-008 is refused (100% of the combination
  matrix).
- **SC-005**: Every invalid cursor case (foreign, expired, tampered, mismatched) produces a byte-identical response.
- **SC-007**: A call with no filter to a contract without the opt-in is refused as invalid arguments in 100% of
  cases; with the opt-in, the first page is returned within the page size and byte budgets.
- **SC-006**: A walk of any length never serves more bytes than the per-session and per-day limits allow.

## Out of Scope

- **Write, update or delete through the query tool** (principle II, Read-Only by Default).
- **Free-form query language, raw SQL, OR, negation, substring or pattern filters** (principles I and V: a
  free-form predicate can probe hidden values and cannot be checked by fixed code rules).
- **Filters or sorts on raw `personal` values, or ranges finer than a bucket or date precision** (principle I).
- **Filters on text fields** (principle I: matching would reveal redacted content).
- **A total row count with each page** (principle I: a count over arbitrary filters is an aggregate; counts belong
  to spec 013 with its minimum group size).
- **Cursors that live longer than the code cap, or that survive a change of rules, ceiling or scope** (principle VI).
- **Configuration that raises the page size or cursor time-to-live above the code cap** (principle VI).
- **Joins beyond the existing one-level relation** (principle I and the depth rule of spec 002).
- **A new runtime dependency for cursor storage** (principle X): cursors use the configured cache store.

## Assumptions

- Hand-written tools stay supported. The generated tool is an additional way to declare a tool, not a replacement.
- The query runs on the contract's model table through the model's own connection, like the relation loader and the
  schema source today.
- Cursor state lives in the cache store named by `cache_store`, like budgets and pseudonym state. It may hold a
  server-side position (for example the last served sort key), which never leaves the server; the cache store
  already holds record keys for pseudonyms.
- The cursor is bound to the caller id, not to a connection, matching how budgets and session pseudonyms are keyed.
- New reason codes: `cursor.invalid` (decision invalid) and `filter.denied` (decision refused). Both are added to the
  reason-code table in the privacy documentation when implemented.
- Row scopes (spec 014) narrow the generated query; filters can only narrow further. Until spec 014 lands, the
  generated tool must not be enabled on a contract whose rows need a row scope.
- There are no users before version 010, so changes to the tool entry format are acceptable.
- Unfiltered listing is off by default (owner decision 2026-11-01). Where a host opts in, bulk export of every allowed
  field within one day's byte budget becomes possible for that contract; the host accepts this by declaring the
  opt-in, and `gate:check` lists it.
