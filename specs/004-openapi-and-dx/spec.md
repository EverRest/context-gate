# Feature Specification: OpenAPI Source and Developer Tooling

**Feature Branch**: `004-openapi-and-dx`

**Created**: 2026-10-30

**Status**: Draft

**Input**: Owner brainstorm (roadmap milestone M5): the API contract becomes a source (endpoints, parameters,
schemas, authentication schemes), and developers get commands to draft the classification catalog, preview
exactly what the assistant would receive, and plant canary values. Nothing is enabled automatically.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ask about an API from its published contract (Priority: P1)

A developer asks what an endpoint accepts and returns. The assistant reads the OpenAPI document, either a
local file or one from an approved host, and gets endpoints, parameters, request and response schemas and
authentication schemes. Examples in the document are removed by default, because they are often copied from
real responses.

**Why this priority**: The contract is the most accurate description of the API, and it is the second most
common question after routes.

**Independent Test**: Local OpenAPI file with two endpoints and one example containing a seeded email; the
assistant gets both endpoints with schemas, and the email does not appear.

**Acceptance Scenarios**:

1. **Given** a local OpenAPI 3.x file, **When** the OpenAPI tool lists endpoints, **Then** each endpoint shows
   method, path, parameters, request schema, response schemas and security requirement names.
2. **Given** an example value containing a seeded email, **When** the default setting applies, **Then** the
   example is removed and the email appears nowhere.
3. **Given** the setting `examples: redacted`, **When** the same example is read, **Then** the example passes
   redaction, canary check and quarantine, and the email is replaced by its placeholder.
4. **Given** a document URL on a host not in the allowlist, **When** the source is configured, **Then**
   loading is refused and the reason is shown to the operator.

---

### User Story 2 - Refuse dangerous network targets (Priority: P1)

The OpenAPI source can fetch a document from a URL. It refuses any address that points inside the
infrastructure: private ranges, loopback, link-local, and the cloud metadata address. The resolved address is
fixed for the request, so a DNS change during the request cannot redirect it. Redirects are refused.

**Why this priority**: Fetching a URL from a tool is a server-side request forgery path; this is a security
requirement, not a convenience.

**Independent Test**: Configure allowed hosts and attempt URLs that resolve to `127.0.0.1`, `10.0.0.5`,
`169.254.169.254`, a hostname that changes resolution between two lookups, and a URL that redirects to an
internal address; all are refused.

**Acceptance Scenarios**:

1. **Given** a URL whose host resolves to a private, loopback, link-local or metadata address, **When** the
   source fetches it, **Then** the fetch is refused with `egress.denied`.
2. **Given** a host that resolves differently on a second lookup, **When** the fetch runs, **Then** the first
   resolved address is used and the request is not redirected.
3. **Given** a response that redirects, **When** the source receives it, **Then** the redirect is refused.
4. **Given** a server that does not answer within 5 seconds, **When** the source fetches, **Then** the fetch
   fails with `source.timeout`.

---

### User Story 3 - Draft the classification catalog for review (Priority: P2)

A developer runs a command that reads the database schema and writes a draft catalog. Every column gets a
suggested class where the name suggests one (email, phone, password, token, IBAN), marked with a comment. Any
column without a suggestion is set to `secret` until a person decides. The command enables nothing and
overwrites nothing without an explicit flag.

**Why this priority**: The catalog is the foundation of the privacy guarantee, and writing it by hand is
error-prone. A safe draft reduces that risk.

**Independent Test**: Schema with `users.email`, `users.password`, `orders.total`; the draft marks email as
`personal // suggested`, password as `secret // suggested`, and total as `secret` with a note that a person
must decide.

**Acceptance Scenarios**:

1. **Given** a column named like an email, **When** the draft is written, **Then** it is marked
   `personal // suggested`.
2. **Given** a column with no suggestion, **When** the draft is written, **Then** it is `secret` with the
   comment `// needs decision`.
3. **Given** an existing `app/Gate/classification.php`, **When** the command runs without `--force`, **Then**
   the file is not changed and the command exits with a message.
4. **Given** a draft, **When** it is loaded by the server, **Then** the server still reads the file as the
   catalog only after the person removes the suggestion markers and saves it (the draft carries no effect).

---

### User Story 4 - See exactly what the assistant would receive (Priority: P2)

A developer runs a preview for a tool with sample arguments. The output is the byte-for-byte response the
assistant would receive, followed by the list of dropped fields with their reasons. Nothing is sent to any
client.

**Why this priority**: Before a contract reaches production, the developer and the DPO need to see the result.

**Independent Test**: Preview of a contract that drops one unclassified field and redacts one email; the output
matches the golden file and lists the dropped field with `field.unclassified`.

**Acceptance Scenarios**:

1. **Given** a tool and sample arguments, **When** the preview runs, **Then** the output equals what the tool
   returns through the firewall, byte for byte.
2. **Given** a dropped field, **When** the preview runs, **Then** it lists the field name and its reason code.
3. **Given** the preview runs, **When** the receipt log is checked, **Then** a receipt is written with
   `source: preview` and no value.

---

### User Story 5 - Plant canary values in documentation and configuration (Priority: P2)

A developer runs a command that generates fake canary values (email and token shapes) and prints where to place
them: documentation, OpenAPI examples and configuration. The command does not write into any file by itself.

**Why this priority**: Canaries prove at runtime that the firewall works on real content (spec 002, US5).

**Independent Test**: Run the command; it prints three placement suggestions and three values, and no file in
the project changes.

**Acceptance Scenarios**:

1. **Given** the command runs, **When** it finishes, **Then** it prints values and suggested locations.
2. **Given** the command runs, **When** the project files are compared before and after, **Then** no file
   changed.
3. **Given** a generated value, **When** it is used, **Then** it has the shape of an email or token and is
   marked as a canary in the output.

---

### Edge Cases

- An OpenAPI file with a `$ref` to a remote URL: the reference is not followed; the entry is listed as
  `ref.external` without content.
- An OpenAPI 2.0 (Swagger) file: refused with `openapi.version` and a message naming the supported version.
- An OpenAPI file larger than 2 MB: refused with `source.too_large`.
- A schema that refers to itself: shown once with a reference marker, not expanded forever.
- `gate:scan` finds a model whose table does not exist: the model is listed as `schema.missing` and no draft
  line is written for it.
- `gate:classify` runs on a database with no tables: writes an empty catalog with a comment explaining why.
- `gate:preview` for a tool whose handler throws: prints the reference id and the reason, not the exception.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The OpenAPI source MUST read OpenAPI 3.x documents from a local file or from a URL.
- **FR-002**: The OpenAPI source MUST return, for each endpoint, method, path, parameters, request schema,
  response schemas and security requirement names, through a contract.
- **FR-003**: Example fields (`example`, `examples`, `x-example`) MUST be removed by default. The configuration
  `examples: redacted` MUST pass them through redaction, canary check and quarantine instead.
- **FR-004**: The schema definitions themselves MUST be returned whole, except for example fields.
- **FR-005**: URL hosts MUST be on an allowlist configured by the operator. An empty allowlist MUST refuse all
  URLs.
- **FR-006**: The source MUST refuse any address in private, loopback, link-local and metadata ranges
  (IPv4 and IPv6), and MUST check every resolved address.
- **FR-007**: The source MUST fix the resolved address for the request duration so a second DNS lookup cannot
  change the target.
- **FR-008**: The source MUST refuse redirects.
- **FR-009**: The source MUST time out after 5 seconds.
- **FR-010**: The source MUST refuse documents larger than 2 MB and documents that are not OpenAPI 3.x.
- **FR-011**: External `$ref` targets MUST NOT be followed.
- **FR-012**: `gate:scan` MUST propose a catalog draft from routes and models and MUST NOT enable any tool or
  change any configuration.
- **FR-013**: `gate:classify` MUST write a draft catalog where a column with a name-based suggestion is marked
  `// suggested`, and any other column is `secret // needs decision`.
- **FR-014**: `gate:classify` MUST NOT overwrite an existing file without `--force`.
- **FR-015**: `gate:preview` MUST print the exact response the caller would receive, followed by dropped fields
  with reason codes.
- **FR-016**: `gate:preview` MUST NOT send anything to any client and MUST write a receipt with `source: preview`.
- **FR-017**: `gate:canary` MUST generate values in the shape of an email and of a token, and MUST print
  suggested placement; it MUST NOT modify any file.
- **FR-018**: All commands in this feature MUST NOT print any value from the database, the configuration or
  the documents.

### Key Entities

- **OpenAPI endpoint**: method, path, parameters, request and response schema references, security names.
- **Allowlisted host**: host name and the resolved addresses pinned for one request.
- **Catalog draft**: a file proposal with per-column suggestions and decision markers.
- **Preview**: the exact output, the dropped-field list, and the receipt reference.
- **Canary value**: a generated fake value and its suggested placement.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In 100% of tests, a seeded email inside an OpenAPI example is absent from the output in the default
  setting.
- **SC-002**: In 100% of tests, fetches to private, loopback, link-local and metadata addresses are refused, and
  redirects are refused.
- **SC-003**: In 100% of tests, the catalog draft enables nothing and leaves every unsuggested column as `secret`.
- **SC-004**: In 100% of tests, `gate:preview` output equals the tool response byte for byte.
- **SC-005**: In 100% of tests, running `gate:canary` leaves all project files unchanged.
- **SC-006**: A developer can produce a reviewed catalog for a fixture application in under 15 minutes using the
  draft and the documented steps.

## Assumptions

- Specs 001–003 are complete: server, pipeline, catalog format, sources and receipts exist.
- OpenAPI 3.0 and 3.1 are supported; Swagger 2.0 is refused.
- The URL allowlist is configured in `config/context-gate.php`; the default is empty.
- The 2 MB document limit and the 5-second timeout are fixed in code (hard caps, like the budget limits in
  spec 002).
- Name-based suggestions use the list from spec 002's quarantine and the roadmap: email, phone, password, token,
  IBAN, card, address.
- `gate:scan` and `gate:classify` read only the schema through spec 003; they never read rows.
- The database query catalog and rows are out of scope (V1).
- `gate:preview` runs as the current console caller and counts its limits in an empty store. It may still create
  day and week pseudonym mappings in the real cache, because byte-equal output needs them; no caller receives them
  from the preview itself.
