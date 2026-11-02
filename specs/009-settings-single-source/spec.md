# Feature Specification: One Path to Settings

**Feature Branch**: `009-settings-single-source`

**Created**: 2026-11-01

**Status**: Clarified

**Input**: Owner request: "improvements the package needs: Redis as the main cache store with real concurrency tests;
one path to settings; caller roles instead of token ids". This spec takes the second item. Specs 008 (Redis cache
store) and 010 (caller roles) take the others.

## Context

Spec 007 added a settings layer: a schema, a validator, security profiles and the effective value of each limit. But
about 46 places in the package (in 29 files: the server, the HTTP layer, the sources, the console commands, the
service provider) still read the raw configuration directly. So the profile, the code caps and the validation apply in
some places and not in others. `gate:check` and the runtime read the same file through different code, and can
disagree.

A second gap: the classification map ships with the package's own source tables (`source_routes`,
`source_config_keys`, `source_schema_items`, `source_docs`, `source_openapi_endpoints`). Publishing the config file
copies them into the host, and the host's file then replaces the whole map. A host that edits or deletes them changes
what the package's own source tools return, without a warning.

This spec makes one typed settings path the only way runtime code reads configuration, validates every value once,
and moves the built-in source tables into code.

## Clarifications

### Session 2026-11-01

- Q: Any open question for this spec? → A: None. The informed defaults under Assumptions stand as written (no marker existed); no requirement changed in this session.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - `gate:check` and the runtime agree (Priority: P1)

An operator runs `gate:check` and it passes. Every call then runs with exactly the values the check accepted. When the
check fails on a key, every call that depends on that configuration is refused with `config.invalid`, not served with
a fallback.

**Why this priority**: An operator trusts `gate:check`. If the runtime reads a value the check never saw, or reads it
differently, the check is false comfort.

**Independent Test**: For each setting key, build one invalid document; `gate:check` fails naming the key, and a call
under the same document is refused with `config.invalid`. For each valid document, the values the check reports equal
the values the call uses.

**Acceptance Scenarios**:

1. **Given** a document that `gate:check` accepts, **When** a call runs, **Then** each limit, ceiling, list and path it
   uses equals the value `gate:check` reports as in force.
2. **Given** a document with a value above its code cap, **When** a call runs, **Then** it is refused with
   `config.invalid`, the same key `gate:check` names.
3. **Given** a document with a wrong type for any key (for example a string where a list of paths is expected),
   **When** `gate:check` runs, **Then** it fails and names the key; it never treats the value as empty and passes.

---

### User Story 2 - The profile and caps apply everywhere (Priority: P1)

An operator selects the strict profile. Every component that uses a limit (budget, rate limit, refusal throttle,
OpenAPI fetch, docs reads) uses the strict value. No component reads a raw value past the profile.

**Why this priority**: A profile that applies in some components only is a profile that does not protect.

**Independent Test**: An architecture test fails when runtime code outside the settings layer reads the package
configuration directly.

**Acceptance Scenarios**:

1. **Given** the strict profile, **When** any limited component runs, **Then** it uses the strict value.
2. **Given** a change of profile, **When** the next process starts, **Then** every component uses the new values,
   and none keeps the old one.
3. **Given** the package source code, **When** the architecture test runs, **Then** no file outside the settings layer
   reads the package configuration directly.

---

### User Story 3 - Built-in source tables cannot be dropped or reclassified (Priority: P1)

A host publishes the config file and adds its own tables to `classification`. The package's source tables keep their
classes, whether the host's file lists them or not. A host can hide a built-in source table or column. Any other change
to a built-in table is reported by `gate:check` and refuses calls with `config.invalid`.

**Why this priority**: The source tools read code, routes, config keys and docs of the host. If a host can reclassify
their columns, a careless edit widens what the model sees.

**Independent Test**: A host classification with no source tables; the source tools still return their columns as
`internal`. A host classification that sets a source column to `public`; `gate:check` fails naming the key.

**Acceptance Scenarios**:

1. **Given** a published config whose `classification` holds only host tables, **When** a source tool runs, **Then**
   its columns are classified as the package defines them.
2. **Given** a host entry that hides `source_docs`, **When** `docs.read` runs, **Then** no column of that table is
   returned, and the receipt records `field.unlisted` for each dropped column.
3. **Given** a host entry that changes the class of a built-in column, or adds a column to a built-in table, **When**
   `gate:check` runs, **Then** it fails and names `classification.<table>.<column>`.
4. **Given** a host config that repeats a built-in table exactly as the package defines it (an older published file),
   **When** `gate:check` runs, **Then** it passes; the repeat changes nothing.

---

### User Story 4 - Receipts prove which values were in force (Priority: P2)

An auditor reads a receipt. Its rule hashes cover the effective values of that call: the merged classification map
(built-in plus host), the effective limits after profile and caps, and the ceiling that applied to that caller.

**Why this priority**: A receipt is the only record of what rules a response passed. A hash over raw configuration
does not change when the profile or the token ceiling changes, so it cannot prove anything.

**Independent Test**: Two calls with the same raw config but different profiles, or different token ceilings, have
different classification hashes. Two calls with different raw configs that resolve to the same effective values have
the same hash.

**Acceptance Scenarios**:

1. **Given** the same raw config and a different profile, **When** two calls run, **Then** their classification hashes
   differ.
2. **Given** an HTTP token with ceiling `public` and one with `internal`, **When** each calls the same tool, **Then**
   their classification hashes differ.
3. **Given** a host config that repeats a built-in source table exactly, and one that omits it, **When** a call runs
   under each, **Then** the hashes are equal.

---

### Edge Cases

- The host caches its configuration (`config:cache`): the settings are read and validated once per process from the
  cached config, the same as from files.
- A long-running process (Octane, stdio server): the settings are validated at the first use in the process and do
  not change until the process restarts (spec 007: a profile switch takes effect at the next start).
- The settings document is missing entirely (the config file was never published): the package defaults apply, they
  are valid, and the gate stays disabled (`enabled` is false).
- A host defines a table whose name starts with `source_` but is not built in: `gate:check` fails; the `source_`
  prefix is reserved for the package.
- A host hides a built-in column that a source tool cannot work without: the column is dropped and the tool returns
  the remaining columns; it never fails open.
- A setting read by a console command only (for example the log channel name used by `gate:token`): it goes through the
  same path as runtime settings.
- Extension points a host binds (its own `AuthorizesTools`, its own tools) read the host's own configuration; this
  spec governs only the package's own settings.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Runtime code, console commands and readiness checks MUST read package settings only through one typed
  settings path. No other code MAY read the package configuration directly.
- **FR-002**: An automated architecture test MUST fail when a file outside the settings layer reads the package
  configuration directly.
- **FR-003**: The settings path MUST validate the whole document once per process, before the first value is used, and
  MUST return typed values: integers, numbers, booleans, enums, lists of strings, maps. A consumer never receives a
  raw untyped value.
- **FR-004**: Every key in the schema MUST have a typed rule. The current `any` rules (`callers`, `canaries.values`,
  `classification`, `http.path`, `http.origins`, `channel`, the source lists, `sources.openapi.examples`) MUST be
  replaced by exact types and shape rules.
- **FR-005**: When the document is invalid, every value read MUST fail closed: tool calls are refused with
  `config.invalid` and `tools/list` returns no tools. A consumer never falls back to a default for a value that is
  present and invalid.
- **FR-006**: `gate:check` MUST use the same settings path and the same validation as the runtime. The values it
  reports as in force MUST be the values the runtime uses, for every key.
- **FR-007**: Effective values (profile, caps, environment) MUST be computed once in the settings path. No consumer
  MAY apply its own `min()` against a cap or its own fallback.
- **FR-008**: The built-in source tables and their classes MUST be defined in package code, not in the published
  config file. The published config file's `classification` MUST ship empty of `source_*` tables.
- **FR-009**: The effective classification map MUST be the built-in tables merged with the host tables. A host entry
  for a built-in table MAY only hide the table or hide columns of it. Any other change to a built-in table (another
  class, a new column) MUST fail validation, naming the key.
- **FR-010**: A host entry that repeats a built-in table exactly MUST be accepted as no change.
- **FR-011**: The `source_` table name prefix MUST be reserved for the package. A host table with that prefix that is
  not built in MUST fail validation.
- **FR-012**: Receipt rule hashes MUST be computed from effective values: the merged classification map, the
  effective limits, and the ceiling that applies to the call (the token ceiling over HTTP, the configured ceiling
  over stdio). Raw configuration MUST NOT be hashed.
- **FR-013**: Two documents that resolve to the same effective values MUST produce the same rule hashes.
- **FR-014**: The settings path MUST stay internal to the package (no public API promise). The documented interface
  for hosts remains the config file and `gate:check`.
- **FR-015**: `docs/configuration.md` MUST describe the merge rule, how to hide a source table or column, and that the
  `source_` prefix is reserved. The comment in the published config that says "keep these entries" MUST be removed.

### Key Entities

- **Settings document**: the host's `context-gate` configuration, as loaded by the framework.
- **Typed settings**: the validated, typed view of the document plus the effective values; the only thing consumers
  read.
- **Built-in classification**: the package's source tables and their classes, defined in code.
- **Effective classification**: built-in plus host tables, after hides; the map the firewall uses and the hash covers.
- **Rule hash**: the hash a receipt records for tools, contracts and classification; computed from effective values.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 0 direct reads of the package configuration remain outside the settings layer (checked by the
  architecture test).
- **SC-002**: For every schema key, an invalid value is reported by `gate:check` and refuses a call with
  `config.invalid` (100% of keys covered by a test).
- **SC-003**: For every valid test document, the values `gate:check` reports equal the values the call uses (0
  mismatches).
- **SC-004**: With a host classification that omits every built-in table, each source tool returns the same columns
  with the same classes as with the built-in defaults (100% of source tools).
- **SC-005**: Every attempt in the tests to reclassify or extend a built-in table fails validation (100%).
- **SC-006**: A profile change or a ceiling change changes the classification hash in 100% of test pairs; equal
  effective values give equal hashes in 100% of test pairs.

## Out of Scope

- **Letting a host lower the class of a built-in source column, or add a column to it** (principle VI: configuration
  narrows, never widens; principle I: an allowlist the host can edit is no longer the package's allowlist).
- **Hot reload of settings inside a running process** (spec 007 edge case: changes take effect at the next start;
  validating once per process is what makes the check and the runtime agree).
- **A public settings API for hosts** (principle X in spirit: a public API is a maintenance promise no consumer asked
  for; the config file is the interface).
- **Settings stored in the database or edited through a user interface** (spec 007 Out of Scope: configuration is a
  file and artisan commands).
- **Changing any limit value, cap or profile value**: this spec moves where values are read, not what they are
  (principle VI).

## Assumptions

- No users exist before version 010, so the published config can change shape (empty `classification`, typed rules)
  without a migration path. An older published file that repeats the built-in tables still validates (FR-010).
- "Hide" is expressed in the host config per table or per column; the exact notation is a plan decision.
- Raising the class of a built-in column (for example `internal` to `personal`) is treated as "another class" and
  fails. Hide is the only narrowing a host needs, and one rule is easier to check than a direction rule.
- Validation once per process means once per PHP-FPM request, once per Octane worker, and once per stdio server start.
- The rule-hash change in FR-012 also fixes today's behaviour, where an HTTP call's classification hash uses the
  configured stdio ceiling instead of the token's ceiling.
- Spec 010 (caller roles) adds role definitions to the settings document and reads them through this path; its rule
  hash adds the role's effective ceiling. This spec should be implemented first.
- Spec 008 (Redis cache store) reads `cache_store` through this path once both exist; neither blocks the other.

## Amendments (owner decisions, 2026-11-01)

- 2026-11-01: implementation order 009 before 015 (015's EffectiveCatalog must be the single config reader per 009 FR-001).
- 2026-11-02: HTTP under invalid settings: the MCP route is registered whatever the settings say, so a call is refused as `config.invalid` with its receipt (not a 404). Boot never throws on an invalid value.
- 2026-11-02: `gate:audit` refuses an invalid document (exit 1, the key named); it does not print `ceiling: invalid` as the spec 007 amendment did.
- 2026-11-02: `gate:check --json` prints an object `{"results": [...], "effective": {...}|null}` instead of a list of results.
- 2026-11-02: `tools.*.risk` is a setting (`low`, `medium` or `high`, optional), read by `gate:audit`.
- 2026-11-02: a hidden built-in source column is recorded as `field.unlisted` on the receipt (not `field.unclassified`).
