# Feature Specification: Row Scopes per Caller

**Feature Branch**: `014-row-scopes`

**Created**: 2026-11-01

**Status**: Clarified

**Input**: Owner request: "features the package needs: a query tool generated from a contract with pagination;
aggregates with a minimum group size; row-level scoping per caller". This spec covers the third item: which rows a
caller may see. The query tool is spec 012; aggregates are spec 013. Caller roles are spec 010 (caller roles),
written in parallel.

## Context

Today a contract limits columns only. Any caller allowed to use a tool sees every row the tool returns, shaped by
the contract and the caller's ceiling. In m-seq, a national federation caller must see only its own federation's
entries and athletes; support staff may see all of them. Today the host must enforce this inside every tool class,
and one missed `where` clause shows another federation's data.

This feature lets a contract declare a row scope. The host application resolves the caller's scope values on every
call, through an extension interface that receives the caller (the token holder), never from the model;
the package applies them to every row source (hand-written tools, relations, the query tool and
aggregates), and refuses the call when the scope cannot be resolved.

## Clarifications

### Session 2026-11-01

- Q: Are a caller's scope values stored on the token when it is issued (fixed for the token's life, changed only by re-issuing), or resolved by the host application from the token's owner on every call? → A: The HOST resolves a caller's scope values on every call through an extension interface (given the caller / token holder). A missing or failing resolution fails closed (no rows). Values are never stored on the token.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A caller sees only the rows of its scope (Priority: P1)

A developer declares that the entries contract is scoped by `federation` through its federation column. A caller
holds the scope value for federation F. When the caller lists entries, every returned row belongs to F.

**Why this priority**: This is the core rule. Without it, column contracts alone cannot serve multi-tenant data.

**Independent Test**: Seed entries for federations F and G. Caller with scope F calls a hand-written tool that
returns all entries. Only F's rows are returned; G's rows are dropped and counted in the receipt.

**Acceptance Scenarios**:

1. **Given** a contract scoped by `federation` and a caller with scope value F, **When** a tool returns rows of F
   and G, **Then** only rows of F reach the response.
2. **Given** the same call, **When** the receipt is written, **Then** it records the scope name `federation`, the
   mode `restricted` and the number of rows removed, and no federation value.
3. **Given** a returned row with no value in the scope column, or a row from which the scope column is missing,
   **When** the firewall runs, **Then** the row is dropped (fail closed).

---

### User Story 2 - Fail closed when the scope cannot be resolved (Priority: P1)

A caller calls a tool whose contract is scoped by `federation`, but the caller has no `federation` value and no
explicit grant to see all rows. The call is refused before the handler runs.

**Why this priority**: A scope that silently resolves to "no restriction" is the leak this feature exists to
prevent.

**Independent Test**: A caller without scope attributes calls a scoped tool. The response is the generic refusal;
the receipt records `scope.unresolved`; the handler was not called.

**Acceptance Scenarios**:

1. **Given** a scoped contract and a caller with no value and no all-rows grant for that scope, **When** the caller
   calls the tool, **Then** the call is refused with the generic text and the receipt records `scope.unresolved`.
2. **Given** the scope source fails or times out, **When** a call needs it, **Then** the call is refused with
   `scope.unresolved`; it is never served unscoped.
3. **Given** a contract that declares neither a scope nor that it is unscoped, **When** `gate:check` runs, **Then**
   it fails and names the contract.
4. **Given** a caller with a scope value list longer than the code cap, **When** it calls a scoped tool, **Then**
   the call is refused with `scope.unresolved`.

---

### User Story 3 - The model cannot change the scope (Priority: P1)

The assistant passes a federation id in its arguments, or a pseudonym of an athlete from another federation. Neither
widens what the caller sees.

**Why this priority**: The model's arguments come from untrusted text. A scope taken from arguments is no scope.

**Independent Test**: A caller scoped to F sends arguments naming G and a pseudonym that resolves to a G record.
The response contains no G row; the pseudonym case is refused like an unknown pseudonym.

**Acceptance Scenarios**:

1. **Given** a caller scoped to F, **When** the arguments name federation G, **Then** the scope stays F; a filter on
   G can only narrow F's rows (to none).
2. **Given** a pseudonym argument that resolves to a record outside the caller's scope, **When** the call runs,
   **Then** the response is identical to an unknown pseudonym and the receipt records `scope.excluded`.

---

### User Story 4 - Relations, query tool and aggregates obey the same scope (Priority: P2)

An entry row has a relation to its athlete. A federation caller lists entries; the related athlete is shown only
when the athlete is in the caller's scope. The query tool (spec 012) and aggregates (spec 013) apply the scope inside
the query, so counts and pages never include other rows.

**Why this priority**: A scope that applies only to the main rows leaks through relations and counts.

**Independent Test**: An entry of F linked to an athlete scoped to G. The relation is returned as null with a
dropped entry `scope.excluded`. An aggregate over the entries counts only F's rows; a query walk returns only F's
rows on every page.

**Acceptance Scenarios**:

1. **Given** a related contract scoped by the same scope name, **When** a related record is outside the caller's
   scope, **Then** the relation is null and the receipt records `<column>` dropped with `scope.excluded`.
2. **Given** a related contract declared unscoped (reference data such as disciplines), **When** it is loaded,
   **Then** no scope is applied to it.
3. **Given** the query tool of spec 012, **When** a scoped caller follows cursors, **Then** every page holds only
   rows of the caller's scope and the cursor is bound to the scope.
4. **Given** an aggregate of spec 013, **When** a scoped caller runs it, **Then** only rows of its scope are counted.

---

### User Story 5 - An explicit all-rows grant for trusted roles (Priority: P3)

Support staff need every federation's entries. Their caller holds an explicit all-rows grant for `federation`. They
see all rows, and every receipt says the grant was used.

**Why this priority**: Without an explicit grant, operators would remove the scope from the contract to serve
support staff, which removes it for everyone.

**Independent Test**: A caller with the grant sees F and G rows; its receipt records mode `all`. A caller with no
grant and no value is refused.

**Acceptance Scenarios**:

1. **Given** a caller with an all-rows grant for `federation`, **When** it calls a scoped tool, **Then** rows of
   every federation are returned and the receipt records mode `all`.
2. **Given** a caller with an all-rows grant for `federation`, **When** a contract is also scoped by another name
   it holds nothing for, **Then** the call is refused with `scope.unresolved`.

---

### Edge Cases

- A caller holds several values for one scope (a person working for two federations): rows of any listed value are
  visible.
- A contract is scoped by two names (federation and event): a row is visible only when it passes both.
- The scope column is not in the contract's projected fields: it is still read for the scope check and never
  returned unless the contract lists it.
- The host handler already filtered by scope: the package check still runs; it is cheap and independent.
- The caller's scope values change during a session (for example a federation membership is revoked): the next call
  resolves the new values, so the change takes effect on the next call; cursors bound to the old values stop working.
- The host resolver is not installed, throws, times out or returns a malformed result: the call returns no rows and
  is refused with `scope.unresolved` (fail closed).
- A pseudonym issued while the caller had a wider scope: resolving it after the scope narrowed is `scope.excluded`.
- Source tools without rows (routes, schema, config keys, docs) have no contract rows to scope; they are unaffected.
- Over stdio, a caller id comes from the OS user and has no token: the host resolver receives that caller identity,
  and with no result the call is refused for scoped contracts.
- Truncation and the work-bound row cap run after the scope filter, so dropped rows never use the byte budget.

## Requirements *(mandatory)*

### Functional Requirements

**Declaration**

- **FR-001**: A contract MUST be able to declare one or more scope names, each mapped to one column of its model
  that holds the row's scope value.
- **FR-002**: A contract MUST be able to declare that it is unscoped. A contract that declares neither MUST be
  `config.invalid`, and `gate:check` MUST fail and name it.
- **FR-003**: A scope column MUST exist in the classification catalog. Its class does not limit the scope check; it
  limits only whether the column may be projected.

**Resolution**

- **FR-004**: A caller's scope values MUST be resolved by the host application on every call, through an extension
  interface that is given the caller (the token holder, or the OS-user caller over stdio). They MUST NOT be stored on
  the token or in a role, and MUST NOT come from tool arguments, the client's `initialize` data, or any model output.
- **FR-005**: For each scope name of the contract, the caller MUST hold either a non-empty list of values or an
  explicit all-rows grant, both returned by the host resolver for that call. Otherwise the call MUST be refused before the handler runs, with the generic refusal and
  the receipt reason `scope.unresolved`.
- **FR-006**: A failure to resolve scope values (no resolver installed, error, timeout, malformed value) MUST fail
  closed: the call MUST return no rows and be refused with `scope.unresolved`.
- **FR-007**: A scope value list MUST have at most 100 values per scope name (code cap). A longer list MUST refuse
  the call with `scope.unresolved`.
- **FR-008**: Resolved scope values MUST be used for the one call that resolved them and MUST NOT be cached across
  calls, so a revoked membership stops applying on the next call. A resolution MUST run within a time limit; the plan
  sets it, with a code cap.

**Enforcement**

- **FR-009**: For hand-written tools, every fetched row MUST be checked against the caller's scope before the
  classify stage. A row whose scope value is missing, null, or not in the caller's values MUST be dropped.
- **FR-010**: The package MUST make the caller's resolved scope available to hand-written tool handlers so they can
  narrow their own queries. This is an optimisation only; FR-009 still applies.
- **FR-011**: Related records MUST be loaded with the related contract's scope applied. A related record outside
  the scope MUST be replaced by null and recorded as dropped with `scope.excluded`.
- **FR-012**: The query tool of spec 012 and the aggregate tool of spec 013 MUST apply the scope inside the query,
  before paging, counting, truncation and the work-bound row cap.
- **FR-013**: A pseudonym argument that resolves to a record outside the caller's scope MUST be refused with the
  same response as an unknown pseudonym; the receipt records `scope.excluded`.
- **FR-014**: Arguments and filters MUST only narrow the scoped rows. No argument can widen the scope.
- **FR-015**: An all-rows grant MUST be per scope name and explicit. No default, wildcard or empty value MAY mean
  "all rows".

**Receipts and failures**

- **FR-016**: A receipt MUST record, per scope name in force, the scope name, the mode (`restricted` or `all`) and
  the number of rows the scope removed. It MUST NOT record scope values or a hash of them: scope values come from a
  small set (for example federation ids), so a hash could be reversed by trying every value.
- **FR-017**: Scope refusals MUST be quiet to the model (constitution VII): the model sees only the generic refusal,
  identical to other refusals.
- **FR-018**: The rule hash of a scoped contract MUST include its scope declaration, so a cursor (spec 012) or a pin
  bound to the old rules stops working when the scope changes.

### Key Entities

- **Scope name**: a label such as `federation` that a contract maps to one of its columns.
- **Scope declaration**: a contract's list of scope names with their columns, or the statement that it is unscoped.
- **Scope values**: the values of one scope name that a caller holds, resolved by the host on each call. Never logged
  and never stored on the token.
- **Scope resolver**: the host's extension interface that, given the caller, returns scope values or an all-rows grant
  for each scope name.
- **All-rows grant**: an explicit permission for one scope name that lets a caller see all rows of that scope.
- **Scope check result**: per call, the names in force, their mode, and the count of rows removed.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In the test suite, no response to a scoped caller contains a row, related record, page or count from
  outside its scope (0 cases across hand-written tools, relations, query tool and aggregates).
- **SC-002**: Every call where the scope cannot be resolved is refused before the handler runs (100%).
- **SC-003**: No receipt or log line contains a scope value (0 occurrences of seeded sentinel scope values).
- **SC-004**: No argument combination widens a caller's visible rows (0 cases in the argument fuzz suite).
- **SC-006**: With the resolver missing, failing or timing out, 0 rows are returned for a scoped contract, and a
  change of a caller's scope values in the host applies to its next call (100% of test cases).
- **SC-005**: A developer scopes an existing contract by adding one declaration, without changing its tool class.

## Out of Scope

- **Scope values taken from tool arguments, prompts or the client's `initialize` data** (principle V, Code Decides,
  and principle III).
- **A default that means "all rows" when a scope is missing** (principles I and III: fail closed, deny by default).
- **Scopes that grant access to columns or classes above the caller's ceiling** (principle I): a scope narrows rows
  only and never widens a field rule.
- **Writing or changing scope assignments through MCP tools** (principle II).
- **An external policy engine or attribute service as a dependency** (principle X).
- **Scope values or their hashes in receipts or logs** (principles IV and VII).
- **Configuration that raises the value-list cap or the cache time-to-live above the code cap** (principle VI).

## Assumptions

- Scope values and all-rows grants are not stored in the package. They come from the host's scope resolver on every
  call (owner decision 2026-11-01). Spec 010 (caller roles) still defines roles; its reserved scope-attributes key
  carries no values for this spec. The resolver interface is new public API of the package.
- The per-call resolution adds one host call per scoped tool call; a slow resolver is bounded by the time limit in
  FR-008 and fails closed.
- Scope values are compared as exact strings or integers; no ranges or hierarchies (a region containing
  federations) in this spec.
- Requiring every contract to declare a scope or unscoped (FR-002) is a breaking change for existing contracts;
  there are no users before version 010, so it is acceptable.
- New reason codes: `scope.unresolved` (decision refused) and `scope.excluded` (a dropped-field or refusal reason).
- Specs 012 and 013 may ship before this spec, but a generated tool on data that needs a row scope must not be
  enabled until this spec is implemented.
- A revoked federation membership takes effect on the caller's next call, because values are never stored on the
  token.

## Amendments (owner decisions, 2026-11-01)

- 2026-11-01: a best-effort timeout does not interrupt a synchronous PHP call; the time limit is checked after the call returns.
- 2026-11-01: implementation order is 010, then 014, then 012, then 013.
- 2026-11-01: ResolvesScopes becomes a public @api interface. Accepted.
