# Feature Specification: k-Anonymous Aggregates

**Feature Branch**: `013-k-anonymous-aggregates`

**Created**: 2026-11-01

**Status**: Clarified

**Input**: Owner request: "features the package needs: a query tool generated from a contract with pagination;
aggregates with a minimum group size; row-level scoping per caller". This spec covers the second item: counts and
group-by over a contract, where no group smaller than a minimum size k is shown. The query tool is spec 012; row
scopes are spec 014.

## Context

Product owners and analysts ask questions such as "how many entries per nation for this event" or "how many
athletes qualified per age band". Today the only aggregate is the `Field::aggregate()` form: a count of the rows in
one response, with no grouping and no minimum size. A count of 1 in a narrow filter identifies a person as surely as
a name does.

This feature adds a declared aggregate tool: counts grouped by allowed fields, with every group smaller than k
suppressed. It reuses the filter rules of spec 012 so that the filters cannot be used to isolate one record.

## Clarifications

### Session 2026-11-01

- Q: Should the date form (year or month) become allowed for `personal` columns, for example to group athletes by birth year? → A: Year or month of a `personal` date column is allowed ONLY as an aggregate grouping key (subject to k). In row results the field stays forbidden as today (`Field::date` stays disallowed for personal columns in row contracts).
- Q: Beyond deterministic suppression and the per-day query limit, should counts also be rounded (for example to a multiple of 5) to blunt differencing? → A: No rounding. Protection relies on deterministic suppression of groups below k plus the per-caller daily aggregate-query budget; the residual differencing risk is documented.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Count rows grouped by an allowed field (Priority: P1)

An analyst asks "how many confirmed entries per nation for event E". The assistant calls the aggregate tool with a
filter on the event and a group-by on the nation field. The response lists each nation with its count. Nations with
fewer than k entries are not listed by name.

**Why this priority**: This is the question analysts ask most, and it needs no row-level data at all.

**Independent Test**: Seed 3 groups with 12, 7 and 2 rows, k = 5. Call with a group-by. The response shows the 12
and the 7 by name and does not show the group of 2.

**Acceptance Scenarios**:

1. **Given** groups of sizes 12, 7 and 2 and k = 5, **When** the tool is called, **Then** the two groups of 12 and 7
   are returned with their counts and the group of 2 is not named.
2. **Given** a group-by on a field the declaration does not allow, **When** the tool is called, **Then** the call is
   refused as invalid arguments.
3. **Given** a declaration that allows group-by on a pseudonym field, **When** `gate:check` runs, **Then** it fails
   and names the tool and the field.

---

### User Story 2 - Small groups are suppressed so totals cannot reveal them (Priority: P1)

The response must not let the caller recover a suppressed count by arithmetic. Suppressed groups are merged into one
"other" group when that group itself has at least k rows. When it has fewer, the "other" group and the total are both
withheld, and a `Notice:` says that some groups were suppressed.

**Why this priority**: Suppressing a group but returning the exact total gives the group's count back by
subtraction. Without this rule the minimum size protects nothing.

**Independent Test**: Seed groups 12, 7, 3, 3. With k = 5 the response shows 12, 7, "other" = 6 and total 25. Seed
groups 12, 7, 2 instead; the response shows 12 and 7, no "other", no total, and a notice.

**Acceptance Scenarios**:

1. **Given** suppressed groups whose sum is at least k, **When** the tool is called, **Then** one "other" group with
   that sum is returned and the total is returned.
2. **Given** suppressed groups whose sum is between 1 and k - 1, **When** the tool is called, **Then** neither
   "other" nor the total is returned, and a `Notice:` says some groups were suppressed.
3. **Given** a call with no group-by whose total is between 1 and k - 1, **When** the tool is called, **Then** the
   count is withheld and a `Notice:` says the result is below the minimum group size.
4. **Given** percentages are requested, **When** the total is withheld, **Then** percentages are withheld too.

---

### User Story 3 - Group personal data only through coarse forms (Priority: P2)

An analyst asks "how many athletes per age band". The age column is personal. The aggregate tool allows grouping on
the bucket form the contract declares for it, never on the raw age, a pseudonym or a name.

**Why this priority**: Most analyst questions about people group by a band or a period, not by a public field.

**Independent Test**: A contract with a personal birth date. Group-by on its bucket form or on its year or month
works; a declaration grouping by the raw column or by a pseudonym fails `gate:check`.

**Acceptance Scenarios**:

1. **Given** a personal field declared as a bucket, **When** the caller groups by it, **Then** each group is labelled
   by its bucket bounds and small groups are suppressed as in Story 2.
2. **Given** a declaration grouping by a pseudonym or a raw personal column, **When** `gate:check` runs, **Then** it
   fails and names the field.
3. **Given** a personal date column declared with year or month precision as a group-by key, **When** the caller
   groups by it, **Then** each group is labelled by its year or month and groups below k are suppressed. The same
   field in a row contract (`Field::date`) is still refused for a `personal` column by `gate:check`.

---

### User Story 4 - Repeated queries cannot be differenced cheaply (Priority: P2)

A caller tries to recover a suppressed group by asking overlapping questions: the same question with and without
one filter, or with the filter moved one bucket. The package makes the same query always suppress the same groups,
limits how many aggregate queries a caller may run per day, and documents the risk that remains.

**Why this priority**: Minimum group size alone is known to fail against differencing. The package cannot prevent
every such attack without noise, but it must make them slow, visible and documented.

**Independent Test**: Run the same aggregate twice; the responses are identical. Exceed the per-day aggregate query
limit; the next call is refused with `aggregate.budget`. The receipts show the field names and operators of every
query, so an operator can see the pattern.

**Acceptance Scenarios**:

1. **Given** unchanged data, **When** the same aggregate query is run twice, **Then** the same groups are suppressed
   and the same counts are returned.
2. **Given** a caller at its per-day aggregate query limit, **When** it calls an aggregate tool, **Then** the call is
   refused and the receipt records `aggregate.budget`.
3. **Given** the privacy documentation, **When** an operator reads it, **Then** it states the differencing risk and
   the protections that apply.

---

### User Story 5 - Aggregates respect ceilings and row scopes (Priority: P2)

A national federation caller asks for counts per discipline. The counts cover only the rows its row scope allows
(spec 014). k is applied after the scope, so a federation with 3 entries in a discipline sees that group suppressed.

**Why this priority**: Without this rule an aggregate would count rows the caller may not see, and the count would
disclose them.

**Independent Test**: Two scopes over one fixture. Each caller's totals equal the count of its own rows; no caller's
response changes when rows outside its scope are added.

**Acceptance Scenarios**:

1. **Given** a scoped caller, **When** it runs an aggregate, **Then** only rows in its scope are counted.
2. **Given** rows outside the caller's scope are added, **When** the same aggregate is run again, **Then** the
   response is unchanged.
3. **Given** a group-by field above the caller's ceiling, **When** the caller groups by it, **Then** the call is
   refused and the receipt records `filter.denied`.

---

### Edge Cases

- A count of 0: groups with no rows are not listed at all; a total of 0 is returned, since it reveals no record.
- All groups are below k: no group is named; "other" and the total follow the Story 2 rule.
- Exactly one group is suppressed and "other" would equal it: "other" is returned only when its sum is at least k,
  so this case is withheld.
- Null values in a group-by field form their own group, labelled as missing, and follow the same k rule.
- Two group-by fields: k applies to each combined cell, not to each field alone.
- The number of groups exceeds the cap: the largest groups are kept, the rest are merged into "other" under the k
  rule, and a `Notice:` says the result was cut.
- The aggregate query budget store is unavailable: the call is refused (fail closed), not served uncounted.
- The existing `Field::aggregate()` form returns a count between 1 and k - 1: it is returned as null and the
  receipt records the field as suppressed.
- Filters narrow the population to a few rows: the k rule applies to the filtered population, so the result is
  withheld, not shown.

## Requirements *(mandatory)*

### Functional Requirements

**Declaration**

- **FR-001**: A host MUST be able to declare an aggregate tool from a contract without writing a tool class. The
  declaration names the group-by fields, the filterable fields (with the operators of spec 012 FR-006), and an
  optional k.
- **FR-002**: The generated aggregate tool MUST follow every rule of a tool: off until enabled, gate ability, per
  caller allow list, fingerprint pin over name, description, input schema and annotations.
- **FR-003**: Group-by MUST be allowed only on plain fields of class `public` or `internal`, and on the bucket and
  date forms of a field. The year or month of a `personal` date column MUST be allowed ONLY as an aggregate group-by
  key, subject to k (FR-006, FR-007); no finer precision is allowed. This allowance MUST NOT change row contracts:
  `Field::date` stays disallowed for `personal` columns in row results. Group-by MUST NOT be allowed on a pseudonym,
  a text field, a relation, an aggregate field, or a raw `personal` column. A declaration that breaks this rule MUST be `config.invalid` and fail `gate:check`.
- **FR-004**: A query MUST group by at most 2 fields (code cap). Configuration may lower it.
- **FR-005**: The only measure in this spec MUST be a row count, with an optional percentage of the reported total.

**Minimum group size**

- **FR-006**: k MUST have a code floor of 5. The default is 5. Configuration and the declaration MAY raise k up to a
  code cap of 1000, and MUST NOT lower it below 5. A value below the floor or above the cap MUST fail `gate:check`.
  Rationale: k is a protection; configuration narrows protections and never widens them (principle VI).
- **FR-007**: Every group, or combined cell for two group-by fields, with a count from 1 to k - 1 MUST be
  suppressed: its label and its count are not returned.
- **FR-008**: Suppressed groups MUST be merged into one "other" group only when their sum is at least k.
- **FR-009**: The total MUST be returned only when it equals the sum of all returned counts (named groups plus
  "other"). When suppressed rows are not covered by a returned "other", the total and all percentages MUST be
  withheld.
- **FR-010**: A query with no group-by MUST return its count only when the count is 0 or at least k.
- **FR-011**: When anything is suppressed or withheld, the response MUST carry a `Notice:` block that says so,
  without naming a suppressed group or giving a suppressed count.
- **FR-012**: The existing `Field::aggregate()` form MUST follow the same floor: a count from 1 to k - 1 is returned
  as null and recorded in the receipt as a suppressed field.

**Differencing**

- **FR-013**: Suppression MUST be deterministic: the same query over the same rows, scope and rules MUST return
  the same response.
- **FR-014**: Each caller MUST have a per-UTC-day limit on aggregate queries. Default 100, code cap 500;
  configuration may only lower it. The counter MUST have a time-to-live that ends at the next 00:00 UTC. A call over
  the limit MUST be refused with the generic refusal and the receipt MUST record `aggregate.budget`.
- **FR-015**: Aggregate calls MUST also count against the per-minute, per-session and per-day limits like any call.
- **FR-016**: The privacy documentation MUST state that minimum group size does not stop all differencing or
  attribute-disclosure attacks (for example two overlapping queries whose difference is one small group, or a group
  in which every member shares a value), and MUST list the protections that apply: suppression, the total rule,
  deterministic output, filter limits from spec 012, the per-day query limit and receipts. Counts are exact; they are
  NOT rounded. The documentation MUST state the residual differencing risk this leaves.
- **FR-017**: Filters on an aggregate MUST follow spec 012 FR-006 to FR-011. A narrow filter (for example equality on
  a pseudonym) is allowed, because the k rule then applies to the filtered population.

**Scope, ceiling, receipts**

- **FR-018**: Counts MUST cover only the rows the caller's row scope allows (spec 014). k MUST be applied after the
  scope and after the filters.
- **FR-019**: A group-by or filter on a plain field above the caller's ceiling MUST be refused with `filter.denied`.
- **FR-020**: A receipt for an aggregate call MUST record the group-by field names, the filter field names with
  operators, the k in force, the number of groups returned and the number suppressed. It MUST NOT record a group
  label, a count, or a filter value.
- **FR-021**: The response MUST pass the canary check; group labels are values and are checked like any other.

### Key Entities

- **Aggregate declaration**: the host's statement for one contract: group-by fields, filterable fields, k.
- **Group**: one combination of group-by values with its row count. Shown only when the count is at least k.
- **Other group**: the merged sum of suppressed groups, shown only when the sum is at least k.
- **Minimum group size (k)**: the smallest count that may be shown. Code floor 5, may only be raised.
- **Aggregate query budget**: per caller, per UTC day, counts aggregate calls. Has a time-to-live.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In the test suite, no response contains a count from 1 to k - 1, and no suppressed count can be
  computed from the numbers in one response (0 cases over the fixture matrix).
- **SC-002**: Every declaration that groups by a pseudonym, text or raw personal column fails `gate:check` (100%).
- **SC-003**: Repeating any aggregate query over unchanged data returns a byte-identical response (100%).
- **SC-004**: An analyst answers a "how many per X" question in one call, without row-level data.
- **SC-006**: A group-by on the year or month of a `personal` date column works in the aggregate tool, and a row
  contract that declares `Field::date` on a `personal` column fails `gate:check` (100% of cases).
- **SC-005**: Adding rows outside a caller's scope changes 0 of that caller's aggregate responses.

## Out of Scope

- **Sum, average, minimum, maximum, median and other measures** (principle I: a minimum or maximum returns one
  record's value; a sum over a small group reveals it). They may be specified later with their own rules.
- **Lowering k below the code floor, by configuration or declaration** (principle VI).
- **Group-by on pseudonyms, text, raw personal values or secret columns** (principle I).
- **A noise mechanism or differential privacy library** (principle X for a new dependency).
- **Rounding of counts** (owner decision 2026-11-01): protection relies on deterministic suppression below k and the
  per-caller daily aggregate-query budget; the residual differencing risk is documented (FR-016).
- **Cross-contract joins in one aggregate** (principle I and the one-level relation rule).
- **Writing aggregate results back to the host** (principle II).

## Assumptions

- The aggregate tool reuses the filter rules, schema generation, fingerprinting and receipts of spec 012. It has no
  pagination: the group cap bounds the result.
- The group cap is 100 groups per response (code cap); configuration may lower it.
- Bucket groups are labelled by their declared bounds; date groups by their period at the declared precision.
- Percentages are rounded to one decimal place and computed over the reported total only.
- The per-day aggregate query counter lives in the configured cache store, like the other budgets.
- New reason code: `aggregate.budget` (decision refused). `filter.denied` comes from spec 012.
- Changing the existing `Field::aggregate()` behaviour (FR-012) is a breaking change; there are no users before
  version 010, so it is acceptable.
- Year or month of a `personal` date column is a valid group-by key only in the aggregate tool; the date form of a
  `personal` column stays forbidden in row contracts (`Field::date` unchanged there). Because the date precision
  is year or month, the k rule applies to each period group.
- Counts are exact. No rounding is applied; the differencing risk that remains (two overlapping queries whose
  difference is one small group) is accepted and documented.
