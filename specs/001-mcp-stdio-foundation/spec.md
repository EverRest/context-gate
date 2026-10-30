# Feature Specification: MCP stdio Foundation

**Feature Branch**: `001-mcp-stdio-foundation`

**Created**: 2026-10-30

**Status**: Draft

**Input**: Owner brainstorm (roadmap milestones M1–M2): a local MCP server for Laravel 12+ that an IDE
assistant can connect to over stdio. Every tool is denied unless explicitly allowed. Nothing reads data
yet; this feature only proves the transport, the visibility rules and the error model.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Connect an IDE assistant to the application (Priority: P1)

A developer adds the server to their assistant (Claude Code, Cursor, VS Code). The assistant starts the
server as a local process, completes the handshake and lists the tools it is allowed to see. With the
default configuration the list is empty.

**Why this priority**: Without a working handshake nothing else can be used or tested.

**Independent Test**: Start the server with the package installed and default configuration; send
`initialize` and `tools/list`; confirm a valid protocol response and an empty tool list.

**Acceptance Scenarios**:

1. **Given** the package is installed and no tools are configured, **When** the assistant starts the
   server and sends `initialize`, **Then** the server answers with its name, version and capabilities.
2. **Given** the default configuration, **When** the assistant requests the tool list, **Then** the list
   is empty.
3. **Given** a tool is configured and allowed for the caller, **When** the assistant requests the tool
   list, **Then** that tool appears with its name, description and argument schema.

---

### User Story 2 - Only allowed tools are visible and callable (Priority: P1)

An operator decides which tools exist for which caller. A caller sees and can call only the tools it is
allowed to use. A tool the caller is not allowed to use looks exactly like a tool that does not exist,
so the list and the error responses reveal nothing about tools the caller cannot use.

**Why this priority**: This is the core security promise of the product (deny by default).

**Independent Test**: Configure two tools, allow one for a caller; check that the list shows one tool,
and that calling the hidden tool and calling a non-existent tool return identical responses.

**Acceptance Scenarios**:

1. **Given** two configured tools and a caller allowed only the first, **When** the caller lists tools,
   **Then** only the first tool appears.
2. **Given** the same caller, **When** it calls the hidden tool and then a non-existent name, **Then**
   both responses are the same "unknown tool" error.
3. **Given** the hidden tool is called, **When** the receipt is inspected, **Then** it records reason
   `policy.hidden`, and the caller response does not contain that reason.

---

### User Story 3 - A call is checked against policy and arguments (Priority: P2)

When an allowed tool is called, the server checks the arguments against the tool's schema and asks the
policy whether this specific call is allowed. Unknown argument names are rejected. A refusal for a
specific call tells the caller that the call was refused, without exposing the rule.

**Why this priority**: Per-call checks are required before any data source exists, and they define the
error model the later features reuse.

**Independent Test**: Configure one tool with a schema and a policy that refuses one argument value;
call it with a valid value, with an unknown key, and with the refused value.

**Acceptance Scenarios**:

1. **Given** an allowed tool, **When** it is called with arguments that match the schema, **Then** the
   tool handler runs and its result is returned.
2. **Given** an allowed tool, **When** it is called with an argument that is not in the schema, **Then**
   the response is `input.invalid` naming the argument, and the handler does not run.
3. **Given** an allowed tool and a policy that refuses this call, **When** it is called, **Then** the
   response is `policy.denied` without the rule details.

---

### User Story 4 - A changed tool is switched off until reviewed (Priority: P2)

The server records a fingerprint of each tool's description. If the description changes after the
operator approved it, the tool is disabled until someone re-approves it. This blocks a tool whose text
was altered, which could carry prompt injection.

**Why this priority**: The tool description goes into the model's context, so an unnoticed change is a
direct injection path.

**Independent Test**: Approve a tool, change its description in configuration, restart the server; confirm
the tool no longer appears and that its receipt reason is `tool.pin_mismatch`.

**Acceptance Scenarios**:

1. **Given** an approved tool whose description is unchanged, **When** the server starts, **Then** the
   tool is visible and callable.
2. **Given** an approved tool whose description changed, **When** the server starts, **Then** the tool is
   hidden, and the receipt for the attempt records `tool.pin_mismatch`.

---

### User Story 5 - The server refuses to run in production over stdio (Priority: P1)

An operator starts the server in a production environment. The stdio transport refuses to start and
explains why, so no local shell can open an unauthenticated channel to production data.

**Why this priority**: Production access must go through authenticated HTTP only (constitution, principle
VIII).

**Independent Test**: Run the start command with `APP_ENV=production`; confirm a non-zero exit and an
explanation on stderr; confirm nothing is written to stdout.

**Acceptance Scenarios**:

1. **Given** `APP_ENV=production`, **When** the start command runs, **Then** it exits with a non-zero
   code and writes the reason to stderr.
2. **Given** `APP_ENV=local` or `staging`, **When** the start command runs, **Then** the server starts.

---

### User Story 6 - Installation starts from a closed configuration (Priority: P3)

A developer installs the package and runs the install command. The command publishes a configuration in
which every tool is disabled, and explains how to enable tools.

**Why this priority**: The safe default must be the first thing a developer gets.

**Independent Test**: Run the install command in a fresh Laravel 12 application; confirm the configuration
file exists, lists no enabled tools, and the tool list from User Story 1 is empty.

**Acceptance Scenarios**:

1. **Given** a fresh Laravel 12 application, **When** the install command runs, **Then** a configuration
   file is published with every tool disabled.
2. **Given** the install command has run, **When** it runs again, **Then** it does not overwrite an
   existing configuration without an explicit flag.

---

### Edge Cases

- A tool name is requested with a different letter case than configured: treated as a different tool
  (unknown tool).
- The caller sends a request without the `name` parameter: `input.invalid` naming the missing parameter.
- A tool handler throws: the response is a generic failure with a receipt identifier; the exception
  message is written to the log only (constitution, principle VII).
- Configuration lists a tool with no handler class: the server refuses to start with a message naming the
  tool; it does not start with a partial tool list.
- Two configured tools share a name: the server refuses to start.
- Something writes to stdout during boot (for example a debug banner): the process test fails. The
  server must not start with polluted stdout in test runs.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The server MUST be started by a single artisan command and MUST communicate over stdio using
  the MCP protocol.
- **FR-002**: The server MUST answer `initialize` with its name, version and capabilities.
- **FR-003**: With no tools enabled, the tool list MUST be empty.
- **FR-004**: The tool list MUST include only tools the caller is allowed to use, and MUST NOT include any
  other tool.
- **FR-005**: Calling a tool the caller is not allowed to use MUST return the same response as calling a
  tool that does not exist.
- **FR-006**: The real reason for a hidden tool (`policy.hidden`) MUST be recorded only in the receipt.
- **FR-007**: Each tool MUST declare an argument schema. Calls with arguments outside the schema MUST be
  rejected with `input.invalid` before the handler runs.
- **FR-008**: Each allowed call MUST be checked by a per-tool policy. A refusal MUST return `policy.denied`
  without the rule details.
- **FR-009**: The server MUST keep a fingerprint of each tool's description. A tool whose description
  differs from the approved fingerprint MUST be hidden and MUST record `tool.pin_mismatch`.
- **FR-010**: The stdio transport MUST refuse to start when the application environment is `production`,
  and MUST explain the refusal on stderr.
- **FR-011**: Standard output MUST carry only protocol messages. All diagnostics MUST go to stderr.
- **FR-012**: The install command MUST publish a configuration in which every tool is disabled.
- **FR-013**: The install command MUST NOT overwrite an existing configuration unless explicitly told to.
- **FR-014**: Every tool call, including hidden, denied, invalid and failed calls, MUST write a receipt
  containing the tool name, caller, decision and reason code. Receipts MUST NOT contain argument or result
  values.
- **FR-015**: Failures from tool handlers MUST return a generic failure with a receipt identifier. Exception
  messages MUST NOT reach the caller.
- **FR-016**: The server MUST refuse to start if configuration is invalid, and the message MUST name the
  invalid entry.

### Key Entities

- **Tool**: A named capability with a description, an argument schema, a policy and a handler. Has an
  approved description fingerprint.
- **Caller**: The client connection that issued a request. Has the set of tools it is allowed to see and
  call.
- **Receipt**: A record of one call: tool, caller, decision, reason code, receipt identifier. Holds no
  values.
- **Reason code**: A fixed identifier explaining a decision (`policy.hidden`, `policy.denied`,
  `input.invalid`, `tool.pin_mismatch`, and the failure code).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A developer can connect an IDE assistant to a fresh application and see an empty tool list
  within 10 minutes of starting the setup.
- **SC-002**: In 100% of automated tests, calling a hidden tool and calling a non-existent tool return
  byte-identical responses.
- **SC-003**: In 100% of automated tests, no protocol response contains a reason code or rule detail
  intended for the receipt only.
- **SC-004**: In 100% of automated process tests, stdout contains only valid protocol messages.
- **SC-005**: Starting the stdio transport with the production environment fails in 100% of tests.
- **SC-006**: Every call in the test suite produces exactly one receipt, including refused and failed calls.

## Assumptions

- The target is Laravel 12 and later (decision D36). Lumen and older Laravel versions are out of scope.
- The MCP protocol and transport are provided by `laravel/mcp`; this feature overrides only the tool
  listing and tool calling behavior.
- The caller's identity for stdio is the operating-system user of the started process; per-caller rules
  apply through the configuration.
- Data sources (routes, schema, docs, OpenAPI, configuration) are out of scope and come in spec 003.
- The firewall stages (contracts, classification, redaction, canaries, budgets) are out of scope and come
  in spec 002. In this feature a tool handler returns its result directly.
- Receipts are written to a dedicated log channel named `context-gate` (decision D19).
- Tools are defined in configuration and in application code; no tool is discovered automatically.
- Exact names of the configuration keys and the handler base class are decided in the plan phase.
