# Feature Specification: Laravel Sources

**Feature Branch**: `003-laravel-sources`

**Created**: 2026-10-30

**Status**: Draft

**Input**: Owner brainstorm (roadmap milestone M4): the application's own structure becomes available to the
assistant through four read-only sources: routes, database schema, configuration and documentation. Each
source returns structure only. Row data, configuration values and secrets never leave the server. Every
source reads through the firewall from spec 002.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ask how an endpoint is built (Priority: P1)

A developer asks which endpoints exist for a resource and what each one expects. The assistant gets the
method, path, route name, controller, validation rule names and middleware names. Internal and debugging
routes are not listed.

**Why this priority**: Routes are the most common onboarding question and carry no data.

**Independent Test**: Application with a public `orders` API route, an admin route and a Telescope route;
the listing shows the first only, with its rule names and middleware names.

**Acceptance Scenarios**:

1. **Given** a route `POST /api/orders` with a FormRequest, **When** the routes tool is called for `orders`,
   **Then** the response contains method, path, name, controller, rule names and middleware names.
2. **Given** routes under `telescope/*`, `horizon/*`, `_ignition/*`, `_debugbar/*`, `pulse/*` and
   `sanctum/*`, **When** the routes tool lists all routes, **Then** none of them appears.
3. **Given** a config exclude for `admin/*`, **When** the routes tool lists all routes, **Then** no `admin`
   route appears.
4. **Given** a route with a long random segment such as an API key in the path, **When** the route is
   listed, **Then** the segment is replaced by `<SECRET>`.

---

### User Story 2 - Understand the database structure without the data (Priority: P1)

A developer asks how a model is stored and related to others. The assistant gets tables, columns with
their classes, indexes, casts and relations. Columns classified `secret` are absent, and the response says
how many columns were hidden. No row is ever read.

**Why this priority**: The schema answers most "where is X stored" questions, and the secret rule protects
the places where credentials live.

**Independent Test**: Users table with email (`personal`), password (`secret`) and name (`personal`); the
schema shows email and name with their classes, omits password, and reports `hidden_columns: 1`.

**Acceptance Scenarios**:

1. **Given** a model with columns classified `public`, `internal` and `personal`, **When** the schema tool
   is called for it, **Then** each column appears with its class.
2. **Given** a column classified `secret`, **When** the schema tool is called, **Then** the column does not
   appear in any form, and the response contains a count of hidden columns.
3. **Given** a model relation, **When** the schema tool is called, **Then** the relation name, related model
   and type appear, and the related model's secret columns are also hidden.
4. **Given** the tool is called, **When** the database is inspected during the test, **Then** no row query is
   executed.

---

### User Story 3 - See which settings exist and which come from the environment (Priority: P2)

A developer asks which configuration keys exist and where they come from. The assistant gets each key with
its type, and the environment variable name when it is safe to show. Values are never shown.

**Why this priority**: Configuration questions are frequent, but the risk is high, so the rule must be exact.

**Independent Test**: Config with `database.host` from `DB_HOST`, `services.stripe.secret` from
`STRIPE_SECRET` and `app.debug` as a literal; the response shows the first with its name, the second with
`env:<hidden>`, and the third with no variable name; no value appears anywhere.

**Acceptance Scenarios**:

1. **Given** a key whose name and variable contain none of the sensitive words, **When** the config tool
   is called, **Then** the key, its type and the variable name appear.
2. **Given** a key or variable containing `secret`, `key`, `token`, `password`, `dsn`, `private` or
   `credentials`, **When** the config tool is called, **Then** the source shows `env:<hidden>`.
3. **Given** any config value, **When** the tool is called, **Then** no value appears in the response or
   in the receipt.

---

### User Story 4 - Read project documentation from an approved list (Priority: P2)

A developer asks how something is documented. The assistant can read files from the approved documentation
paths only. Secret files, dependency folders, version control data and any path that resolves outside the
approved folders are refused, even when the configuration lists them. Documentation text passes the
firewall's quarantine and redaction.

**Why this priority**: Documentation answers many questions, and it is the source most likely to contain
both secrets and instructions aimed at the model.

**Independent Test**: Allowlist `docs/` and `README.md`; a symlink in `docs/` points to `.env`; a request
for the symlink is refused, a request for a normal Markdown file succeeds, and a configuration that lists
`.env` still returns a refusal.

**Acceptance Scenarios**:

1. **Given** a Markdown file under an allowlisted path, **When** the docs tool reads it, **Then** the text is
   returned wrapped as untrusted with its source name.
2. **Given** a path matching the denylist (for example `.env`, `storage/`, `vendor/`), **When** the docs tool
   reads it, **Then** the request is refused, even if the configuration lists that path.
3. **Given** a symlink inside an allowlisted folder that points outside it, **When** the docs tool reads it,
   **Then** the request is refused.
4. **Given** a file with a non-text extension, **When** the docs tool reads it, **Then** the request is refused.
5. **Given** documentation text that contains a seeded email, **When** the docs tool returns it, **Then** the
   email is replaced by its placeholder.

---

### Edge Cases

- A route has no name: the name field is null, and the route is still listed if it is public.
- A FormRequest that cannot be resolved: the rule names field is empty and the route still appears.
- A model whose table does not exist yet (not migrated): the schema tool returns the model with an error code
  `schema.missing`, not a stack trace.
- A config key whose value is an array: only the key and type `array` are shown, never its items.
- A documentation file larger than the per-call limit of spec 002: it is truncated at a line boundary and
  marked `truncated: true`.
- A path with mixed case (`Docs/Guide.md`) on a case-insensitive file system: matched against the allowlist
  by the resolved real path.
- Known limits of the path policy: a symlink swapped between the policy check and the read is caught only by the
  root check, and a hard link to a denied file inside an allowlisted folder looks like an ordinary file. Do not
  allowlist a folder that others can write to.
- A documentation file that is deleted between listing and reading: the request fails with `source.missing`.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The routes source MUST return method, path, name, controller, validation rule names and
  middleware names for each route it lists.
- **FR-002**: The routes source MUST exclude `telescope/*`, `horizon/*`, `_ignition/*`, `_debugbar/*`,
  `pulse/*` and `sanctum/*` by default, and the bare route of each (`telescope`, `horizon`, and so on). The configuration MAY exclude more, and MUST NOT remove a default
  exclusion.
- **FR-003**: Static path segments and middleware parameters MUST pass the redactor. A path segment of 20 or
  more random characters MUST be replaced by `<SECRET>`.
- **FR-004**: The schema source MUST return tables, columns with their class, indexes, casts and relations
  for each model it lists.
- **FR-005**: A column classified `secret` MUST NOT appear in the schema in any form. The response MUST
  include a count of hidden columns per model.
- **FR-006**: The schema source MUST NOT execute any query that reads row data (SC-002 is verified by
  asserting no row query).
- **FR-007**: The config source MUST return each key with its type. Values MUST NOT be returned in any form. A key segment that is itself a secret or an address MUST be replaced by `<SECRET>`: a segment of 16 or more key-like characters (letters, digits, `_` and `-`) with at least one digit or hyphen, or any segment containing an `@`. Identifiers such as `notification_channels` stay visible.
- **FR-008**: The config source MUST show an environment variable name only when neither the key nor the
  variable name contains `secret`, `key`, `token`, `password`, `dsn`, `private` or `credentials`
  (case-insensitive). Otherwise it MUST show `env:<hidden>`.
- **FR-009**: The docs source MUST read only files under the allowlisted paths in configuration. The default
  allowlist is `docs/`, `README.md` and `CHANGELOG.md`.
- **FR-010**: The docs source MUST refuse any path that matches the code denylist: `.env*`, `*.key`, `*.pem`,
  `auth.json`, `storage/`, `vendor/`, `.git/`, `node_modules/` and any dotfile. Configuration MUST NOT be able
  to remove an entry from this denylist.
- **FR-011**: The docs source MUST resolve symbolic links to their real path and apply the allowlist and
  denylist to that real path.
- **FR-012**: The docs source MUST read only files whose extension is one of `.md`, `.txt`, `.rst`, `.yaml`,
  `.yml`, `.json`.
- **FR-013**: Text returned by the docs source MUST pass the firewall's normalization, redaction, canary check
  and quarantine.
- **FR-014**: Each source MUST be exposed as a tool with a declared contract (spec 002), and its response MUST
  pass the firewall pipeline.
- **FR-015**: A source that cannot read its input MUST return a reason code (`source.missing`,
  `schema.missing`) and MUST NOT return a stack trace or file content.
- **FR-016**: No source MUST write to the file system, the database or the configuration.
- **FR-017**: Denied documentation paths and hidden columns MUST be recorded in the receipt by count and reason,
  never by name of a secret column or path of a secret file.

### Key Entities

- **Route entry**: method, path, name, controller, rule names, middleware names.
- **Model schema**: model name, table, columns with class, indexes, casts, relations, hidden column count.
- **Config key**: key, type, environment variable name or `env:<hidden>`.
- **Document**: source name, real path, text (after the firewall), truncation flag.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In 100% of tests, no route on the default exclusion list and no config value appears in any
  source response.
- **SC-002**: In 100% of tests, the schema source executes zero row-reading queries.
- **SC-003**: In 100% of tests, a `secret` column is absent from the schema response and the count is correct.
- **SC-004**: In 100% of tests, a documentation request for a denylisted path, a symlink escaping the
  allowlist or a non-text extension is refused.
- **SC-005**: In 100% of tests, seeded personal values in documentation are replaced before the response is
  returned.
- **SC-006**: A developer can list the endpoints of one resource with its rule names in one assistant request.

## Assumptions

- Spec 001 and spec 002 are complete: server, tool registry, pipeline, redaction, quarantine and receipts exist.
- Sources read the running application's metadata (routes, models, configuration) and the project's files.
  No database connection is opened for row data.
- Route validation rules are read from the FormRequest class names and their rule method only when the class
  can be resolved without running the application's constructor logic.
- The database schema is read through the framework's schema inspector, which may require a reachable
  database connection for the table list; when it is unreachable the source returns `schema.missing`.
- Documentation paths are relative to the application root.
- OpenAPI sources (spec 004), database query catalogs (later version) and gate:scan/classify (spec 004) are
  out of scope.
