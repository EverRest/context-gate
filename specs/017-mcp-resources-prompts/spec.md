# Feature Specification: MCP Resources and Prompts

**Feature Branch**: `017-mcp-resources-prompts`

**Created**: 2026-11-01

**Status**: Clarified

**Input**: Owner request: "improvements and features the package needs". The server advertises only tools today.
MCP also defines resources (read-only content at a stable URI) and prompts (reusable prompt templates with
arguments). This spec adds both, under the same rules as tools: deny by default, the same firewall, classification
and receipts, visibility per caller, and fingerprint pinning.

## Clarifications

### Session 2026-11-01

- Q: Do resource reads share the budgets of tool calls, or get their own lower budgets within the code caps? (FR-011) → A: Resource reads SHARE the same per-call/session/day budgets and rate limits as tool calls, so resources cannot bypass budgets.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Read allowlisted documentation as resources (Priority: P1)

An operator exposes the documentation allowlist as resources. A client lists the documents it may read and reads one
by its URI. The read goes through the same path policy, firewall, canary check, budget and receipt as `docs.read`.

**Why this priority**: Documentation is the most common context an assistant asks for, and MCP clients show resources
directly to the user.

**Independent Test**: Allow one directory for a caller; list resources; only files under that directory appear. Read
one; the text matches what `docs.read` returns for the same file, and a receipt is written.

**Acceptance Scenarios**:

1. **Given** a docs resource allowed for the caller, **When** the caller lists resources, **Then** each readable file
   appears once with a stable URI, a name and a media type.
2. **Given** a listed URI, **When** the caller reads it, **Then** the content is the firewall output for that file,
   marked as untrusted text, and a receipt is written.
3. **Given** a URI for a denylisted path, a path outside the allowlist, or a path that escapes through a link,
   **When** the caller reads it, **Then** the answer is the same as for an unknown resource and the receipt records
   the refusal.
4. **Given** a document that contains a canary value, **When** it is read, **Then** the response is blocked and the
   receipt records `canary.hit`.

---

### User Story 2 - Read model schemas as resources (Priority: P2)

An operator exposes the schema of each allowlisted model as a resource. The content is the same as
`schema.describe` for that model: columns, indexes, casts and relations, with `secret` columns hidden.

**Why this priority**: A schema rarely changes, so a resource the client can keep fits better than a repeated tool
call.

**Independent Test**: Allow schema resources for one caller; the list holds one URI per allowlisted model; the
content of one equals the `schema.describe` rows for that model.

**Acceptance Scenarios**:

1. **Given** schema resources allowed for the caller, **When** the caller lists resources, **Then** one URI per
   allowlisted model appears.
2. **Given** a model that is not allowlisted, **When** its URI is read, **Then** the answer is the same as for an
   unknown resource.
3. **Given** a column classified `secret` in the effective catalog, **When** the schema resource is read, **Then**
   the column is absent and counted as hidden, as in `schema.describe`.

---

### User Story 3 - Host-defined prompts with validated arguments (Priority: P2)

A host defines a prompt, for example "qualification status for a discipline". The prompt has a name, a description,
an argument schema, a fixed template text and the list of tools it refers to. A client gets the prompt with
arguments; the gate validates the arguments like tool arguments and returns the template filled with them. A prompt
never runs a query and never puts a data value into its text.

**Why this priority**: Prompts let the host guide the assistant towards the right tools without exposing data.

**Independent Test**: Define a prompt with one string argument; get it with a valid argument; the text is the
template with the argument. Get it with an invalid argument; the answer is `Invalid arguments: ...` and a receipt is
written.

**Acceptance Scenarios**:

1. **Given** a prompt allowed for the caller, **When** the caller gets it with valid arguments, **Then** the result is
   the template text with the arguments in place, and a receipt is written.
2. **Given** arguments that fail the schema, **When** the caller gets the prompt, **Then** the result is
   `Invalid arguments: ...` and the receipt records `input.invalid`.
3. **Given** an argument that contains a canary value, **When** the caller gets the prompt, **Then** the request is
   refused before the template is filled and the receipt records `canary.hit`.
4. **Given** a pseudonym argument, **When** the prompt is filled, **Then** the pseudonym string is inserted as sent;
   it is never resolved to an internal id.
5. **Given** a prompt that refers to a tool the caller cannot see, **When** the caller lists prompts, **Then** the
   prompt is absent, and getting it answers like an unknown prompt.

---

### User Story 4 - Visibility, capabilities and pinning (Priority: P1)

Resources and prompts are off until configured, enabled and allowed for a caller, like tools. A caller sees only what
it may use; anything else is absent, and using it answers exactly like something that does not exist. The server
advertises the resources or prompts capability only when the caller can see at least one. Each resource and prompt
is pinned by a fingerprint; a change hides it until the fingerprint is renewed.

**Why this priority**: Without this, resources and prompts would bypass principle III, which every other part of the
gate follows.

**Independent Test**: With nothing configured, `initialize` advertises only tools, and listing resources or prompts
returns an error like an unknown method. Configure one prompt for caller A; A sees the prompts capability, B does
not. Change the prompt's template; it disappears for A until its fingerprint is renewed.

**Acceptance Scenarios**:

1. **Given** no resource and no prompt configured, **When** a client initialises, **Then** only the tools capability
   is advertised, as today.
2. **Given** a resource allowed for caller A only, **When** caller B lists resources or reads its URI, **Then** the
   list is empty or the capability is absent, and the read answers like an unknown resource; the receipt records the
   real reason.
3. **Given** a prompt whose name, description, argument schema, template or tool list changed, **When** any caller
   lists prompts, **Then** the prompt is absent until its fingerprint is renewed, and the receipt of a get records the
   pin mismatch.
4. **Given** `enabled` is `false`, **When** any caller lists or reads resources or prompts, **Then** everything is
   absent.
5. **Given** a fingerprint command, **When** an operator runs it for a resource or a prompt, **Then** it prints the
   fingerprint and nothing else.

---

### Edge Cases

- A documentation directory holds more files than the list cap: the list is paged within a code cap; files past the
  cap are not listed and can still be read by URI if allowed.
- A file is added to an allowed directory after the server started: it is listed on the next list request. The list
  is not pushed (no change notifications).
- A URI with `..`, an encoded separator, a different letter case or a Unicode look-alike of a denylisted name: it is
  checked with the same folding and path rules as `docs.read` and refused the same way.
- A resource read and a tool call share a session: both count toward the same per-minute and byte budgets (see FR-011).
- A prompt template that holds a canary value or a value the redactor matches: the prompt fails its load check and is
  hidden; `gate:check` reports it.
- A prompt argument that is very long: the argument schema's length limit applies, within a code cap on any prompt
  argument.
- A client asks for argument completion: not supported; the request answers like an unknown method.
- A schema resource for a model whose table is missing: the read fails with `schema.missing`, as `schema.describe`
  does.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The gate MUST support read-only resources of two kinds: documents under the documentation allowlist and
  schemas of allowlisted models.
- **FR-002**: Each resource MUST have a stable URI that is derived from configuration (the allowlisted path relative to
  the application root, or the model name), never from an absolute path, and never containing a data value.
- **FR-003**: A resource read MUST pass the same stages as the matching tool: visibility, admission, path or model
  policy, fetch, the firewall stages, the canary checks, the budget and the receipt.
- **FR-004**: The gate MUST support host-defined prompts with a name, a description, an argument schema, a fixed
  template text and a list of the tools the prompt refers to.
- **FR-005**: Prompt arguments MUST be validated like tool arguments, including the canary check on arguments. A
  failure is `Invalid arguments: ...` for a schema error and a generic refusal otherwise.
- **FR-006**: A prompt MUST NOT run a query, call a tool or a source, or resolve a pseudonym. Its result is only the
  template text with the client's arguments inserted as sent.
- **FR-007**: A prompt template MUST be checked when it is loaded: a template that holds a canary value or text the
  redactor matches makes the prompt invalid; an invalid prompt is hidden and `gate:check` reports it.
- **FR-008**: Resources and prompts MUST be denied by default. Each needs a configuration entry, `enabled`, a gate
  ability and a listing for the caller, as tools do.
- **FR-009**: A resource or prompt the caller may not use MUST be absent from its lists, and using it MUST give the
  same answer as using one that does not exist. A prompt that refers to a tool the caller cannot see MUST be treated
  as not visible.
- **FR-010**: The server MUST advertise the resources capability or the prompts capability only when the caller can
  see at least one resource or prompt. Neither capability offers subscriptions or change notifications.
- **FR-011**: Resource reads and prompt gets MUST count toward the caller's per-minute limit, and resource reads toward
  the byte budgets. Resource reads MUST SHARE the same per-call, per-session and per-day budgets and the same rate
  limits as tool calls, with no separate or additional allowance, so a resource read cannot bypass a budget that a
  tool call would hit. A read refused for a budget is recorded with the same reason code as a tool call.
- **FR-012**: Each resource and prompt MUST be pinned by a fingerprint of everything the client sees about it (URI or
  name, description, media type, argument schema, template text and tool list). A mismatch hides it and the receipt
  records the pin mismatch.
- **FR-013**: The fingerprint command MUST print the fingerprint of a resource or a prompt.
- **FR-014**: Every resource read and prompt get MUST write a receipt, including refusals and failures. The receipt
  records the kind (tool, resource or prompt), the configured entry name and a hash of the URI; it never records the
  document path, the arguments or the content.
- **FR-015**: The list of resources MUST be paged and capped in code.
- **FR-016**: `gate:audit` MUST list enabled resources and prompts with their ability, fingerprint state and rule
  hashes, like tools.

### Key Entities

- **Resource**: a configured entry with a kind (document or schema), a URI, a name, a media type, a gate ability and a
  fingerprint.
- **Prompt**: a configured entry with a name, a description, an argument schema, a template text, the tools it refers
  to, a gate ability and a fingerprint.
- **Caller listing**: per caller, the tools, resources and prompts it may see.
- **Receipt kind**: tool, resource or prompt.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: With nothing configured, the server's advertised capabilities and answers are unchanged from today
  (100% of protocol test cases).
- **SC-002**: A caller sees a resource or prompt not allowed for it in 0 cases, and the answer for a hidden one equals
  the answer for an unknown one in 100% of cases.
- **SC-003**: No resource content or prompt result contains a seeded personal value, `secret` value or canary value
  (0 cases).
- **SC-004**: Every resource read and prompt get writes a receipt (100%), and no receipt contains a document path,
  argument or content.
- **SC-005**: A changed resource or prompt is hidden until its fingerprint is renewed (100% of cases).
- **SC-007**: A caller that has used a session or day byte budget through tool calls is refused a resource read with
  the same budget reason, and the reverse (100% of test cases); no mix of reads and calls serves more bytes than the
  budget allows.
- **SC-006**: For the same file or model, the resource content equals the matching tool's result (100% of test
  fixtures).

## Out of Scope

- **Write tools, and resources or prompts that change data** (principle II, Read-Only by Default).
- **Resource subscriptions and change notifications pushed to the client** (principle IV, No Response Without a
  Receipt: a push sends content without a call to write a receipt for; and principle III, because a push cannot be
  re-checked against the caller's current visibility).
- **Prompts that embed data values, run queries or call tools on the server** (principle I, Privacy Fails Closed: a
  value would leave through a path that has no contract).
- **Argument completion for prompts or resource templates** (principle I: completion suggests values, which can
  enumerate data).
- **Resource templates with parameters chosen by the client**: deferred; the concrete list in FR-001 is enough for now.
- **Resources for routes, configuration keys and OpenAPI documents**: deferred to a later spec; the tools stay.
- **Sampling requests from the server to the client** (principle V, Code Decides: no model on the security path).

## Assumptions

- `laravel/mcp`, already a dependency, provides the protocol methods for resources and prompts; no new runtime
  dependency is needed. If it does not, the plan must say so before adding one (principle X).
- Resources and prompts are configured under their own keys next to `tools`, and callers list them next to their
  tools. Pre-010, the configuration shape may change freely.
- Resource reads use the same budgets and rate limits as tool calls (owner decision 2026-11-01); the spec adds no
  budget setting for resources.
- Schema resources use the effective catalog of spec 015.
- Receipts carry the kind field defined here; spec 016 sinks store and count it.
- The resource list cap defaults to 1000 entries per page.
- Spec 011's security scope grows to include the resource and prompt handlers.

## Amendments (owner decisions, 2026-11-01)

- 2026-11-01: MCP resources and prompts are assigned to callers through their ROLE, not through a separate callers key.
- 2026-11-01: a caller with no resource (or no prompt) gets an "unknown method" response, not the text in US4 scenario 2.
- 2026-11-01: the `kind` field of the receipt uses format version v2 (016 plan's structured v2).
