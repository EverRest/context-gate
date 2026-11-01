# Feature Specification: Classification Declared on the Model

**Feature Branch**: `015-classification-attributes`

**Created**: 2026-11-01

**Status**: Clarified

**Input**: Owner request: "improvements and features the package needs". Classification today is one large map in
the configuration file. When a host migration adds a column, nothing notices until someone edits the map; the new
column is dropped (fail closed), but nobody decides its class. Hosts should be able to declare the class of each
column next to the model, merge that with the configuration map in a fixed, fail-closed way, and have CI fail when an
allowlisted model has a column with no class.

## Clarifications

### Session 2026-11-01

- Q: Should the CI mode and the catalog also cover every model class in the host application (discovering classes from the file system), or only allowlisted models? (FR-006) → A: Only allowlisted models (the models the host exposes to the gateway); no file-system discovery of host classes.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Declare column classes on the model (Priority: P1)

A developer adds a column in a migration and declares its class on the model in the same change. The gate reads the
declaration and treats the column exactly as if it were in the configuration map.

**Why this priority**: The class is decided by the person who adds the column, in the same review, next to the code
that uses it.

**Independent Test**: A model declares `status` as `internal` and the configuration map does not list the table;
a contract that lists `status` loads and returns the value as plain.

**Acceptance Scenarios**:

1. **Given** a model that declares a column as `internal` and no configuration entry for it, **When** a contract lists
   the column, **Then** the contract loads and the column is classified `internal`.
2. **Given** a model that declares a column with an unknown class, **When** the catalog is built, **Then** the
   catalog is invalid: calls to tools that depend on it are refused with `config.invalid` and `gate:check` fails,
   naming the model and the column.
3. **Given** a model that declares no class for a column, **When** a response holds that column, **Then** the column
   is dropped, as it is today for a column with no class.

---

### User Story 2 - Merge with the configuration map, the stricter class wins (Priority: P1)

A column can have a class from the model and a class from the configuration map. The effective class is always the
more sensitive of the two. The configuration can make a column stricter than the model says (narrow), never less
strict (widen). A configuration entry that tries to widen is an error that `gate:check` reports.

**Why this priority**: Two sources of truth need one fixed answer. Principle VI says configuration narrows
protections and never widens them; the stricter-wins rule makes that true by construction.

**Independent Test**: The model declares a column `personal`; the configuration says `internal`. The column is served
as `personal` and `gate:check` fails naming the configuration key. Swap the two; the column is `personal` and
`gate:check` passes.

**Acceptance Scenarios**:

1. **Given** model `internal` and configuration `personal`, **When** the catalog is built, **Then** the effective
   class is `personal` and `gate:check` passes.
2. **Given** model `personal` and configuration `internal`, **When** the catalog is built, **Then** the effective
   class is `personal`, and `gate:check` fails, naming the configuration key and saying that configuration may not
   widen a model class.
3. **Given** model and configuration agree, **When** the catalog is built, **Then** the class is that class and
   nothing is reported.
4. **Given** a column only in the configuration map, **When** the catalog is built, **Then** it behaves as it does
   today.

---

### User Story 3 - CI fails on an unclassified column (Priority: P1)

The host CI runs `gate:scan` in a CI mode after migrations. It fails when any column of an allowlisted model has no
effective class, and lists each such table and column. A migration cannot reach the main branch with a column nobody
classified.

**Why this priority**: Fail-closed dropping hides the problem; the CI mode surfaces it at review time, in the change
that caused it.

**Independent Test**: Add a column to a fixture model's table without a class; the CI mode exits non-zero and prints
the table and column. Declare the class; it exits zero.

**Acceptance Scenarios**:

1. **Given** every column of every allowlisted model has an effective class, **When** the CI mode runs, **Then** it
   exits 0.
2. **Given** one column has no class, **When** the CI mode runs, **Then** it exits non-zero and lists the model, table
   and column, and nothing else about the column.
3. **Given** the CI mode with JSON output, **When** it fails, **Then** the output is a JSON object listing the
   unclassified columns, and stdout holds only that object.
4. **Given** an allowlisted model whose table does not exist, **When** the CI mode runs, **Then** it fails and names
   the model: a model that cannot be checked does not pass.

---

### User Story 4 - Drafts stay inert until reviewed (Priority: P2)

`gate:classify` still writes a draft with suggestion markers. A draft is never read as a source of classes, by the
catalog or by the CI mode. It becomes active only after a person removes the markers and moves the classes to the
model or to the configuration map.

**Why this priority**: A suggestion is a guess from a column name. A guess must never classify a column on its own.

**Independent Test**: Run `gate:classify` for a model with an unclassified column; the CI mode still fails for that
column, and `gate:check` fails on the draft markers.

**Acceptance Scenarios**:

1. **Given** a draft with markers, **When** the CI mode runs, **Then** the columns in the draft still count as
   unclassified.
2. **Given** a draft with markers, **When** `gate:check` runs, **Then** it fails as it does today.
3. **Given** `gate:classify`, **When** it runs, **Then** it does not change any model file.

---

### User Story 5 - Rule hashes follow the effective catalog (Priority: P2)

The classification rule hash in each receipt covers the merged catalog. A change to a model declaration changes the
hash, just like a change to the configuration map.

**Why this priority**: An auditor must be able to tell from receipts that the classification changed.

**Independent Test**: Change one model declaration; the classification hash in the next receipt differs.

**Acceptance Scenarios**:

1. **Given** a model declaration changes, **When** the next call writes a receipt, **Then** its classification hash
   differs from the previous one.
2. **Given** the order in which models are listed changes and nothing else, **When** a receipt is written, **Then**
   the hash is unchanged.

---

### Edge Cases

- Two allowlisted models use the same table and declare different classes for one column: the stricter class wins and
  `gate:check` fails, naming both models.
- A model declares a column that the table does not have (a stale declaration after a drop): `gate:check` warns and
  names it; the declaration has no effect on responses.
- A model uses a different database connection: its columns are read through that connection, as `gate:scan` and
  `schema.describe` do today.
- A declaration on a parent model class: it applies to a child model only if the child uses the same table. The plan
  decides inheritance; the default is that a child sees the parent's declarations and may only make them stricter.
- The source pseudo-tables (`source_routes` and the others) keep their entries in the configuration map. They have no
  model, and the CI mode does not check them.
- A model declares `secret` for a column a contract lists: the contract is refused, as for a `secret` column in the
  configuration map today.
- Configuration caching in the host: the model declarations are code, so they change with a deploy, not with a cache
  clear.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: A host MUST be able to declare the class of each column on the model that owns its table. The classes
  are `public`, `internal`, `personal` and `secret`.
- **FR-002**: An unknown declared class MUST make the catalog invalid. Calls that depend on the catalog are refused
  with `config.invalid`, and `gate:check` fails naming the model and the column.
- **FR-003**: The effective class of a column MUST be the more sensitive of the model class and the configuration
  class. If only one exists, it is the effective class. If neither exists, the column has no class and is dropped.
- **FR-004**: A configuration class less sensitive than the model class for the same column MUST fail `gate:check`,
  naming the configuration key. At runtime the stricter class applies; the call is not refused for this conflict.
- **FR-005**: Two models declaring different classes for the same table and column MUST resolve to the stricter class
  and MUST fail `gate:check`, naming both models.
- **FR-006**: The catalog MUST read model declarations only from allowlisted models: models listed under
  `sources.eloquent.models` and models named by a configured contract. These are the models the host exposes to the
  gateway. The catalog and the CI mode MUST NOT discover model classes from the file system, and MUST NOT cover a model
  that is not allowlisted.
- **FR-007**: `gate:scan` MUST offer a CI mode that exits non-zero when any column of an allowlisted model has no
  effective class, and lists the model, table and column of each. It prints no row value.
- **FR-008**: The CI mode MUST fail for an allowlisted model whose table cannot be read, naming the model.
- **FR-009**: The CI mode with JSON output MUST print only the JSON object to stdout; diagnostics go to stderr.
- **FR-010**: Drafts written by `gate:classify` MUST NOT be a source of classes for the catalog or the CI mode.
  `gate:classify` MUST NOT modify any model file.
- **FR-011**: The classification rule hash MUST cover the effective merged catalog, ordered so that the listing order
  of models does not change it.
- **FR-012**: A stale declaration (a column the table does not have) MUST be reported by `gate:check` as a warning.
- **FR-013**: The merged catalog MUST be built at most once per process. It is not written to a cache store.
- **FR-014**: The contract load rules (no class, `secret`, `personal` form) MUST apply to the effective class, with
  no change to the rules themselves.

### Key Entities

- **Model declaration**: a model, a column and its declared class.
- **Configuration entry**: a table, a column and its class from the configuration map (unchanged).
- **Effective class**: the stricter of the two for one table and column; absent if neither exists.
- **Conflict**: a configuration entry less strict than a model declaration, or two models disagreeing on one column.
- **Unclassified column**: a column of an allowlisted model's table with no effective class.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In the test suite, configuration never makes a column less sensitive than its model declaration (0
  cases out of all combinations of the four classes).
- **SC-002**: Every column added to a fixture model's table without a class makes the CI mode fail (100% of cases),
  and the output names the column.
- **SC-003**: A draft with markers classifies a column in 0 cases.
- **SC-004**: Every change to a model declaration changes the classification hash (100% of test cases).
- **SC-005**: No output of the CI mode, `gate:check` or `gate:classify` contains a row value (checked with seeded
  canary rows).

## Out of Scope

- **Covering every model class in the host application, or discovering host classes from the file system** (owner
  decision 2026-11-01; principle I is unaffected, because a model the gateway cannot reach exposes nothing).
- **A model-wide default class for undeclared columns** (principle I, Privacy Fails Closed): a column nobody
  reviewed would get a class and could be returned; an unclassified column must be dropped.
- **Configuration that lowers a model-declared class** (principle VI, Config Narrows, Never Widens Protections).
- **Suggestions that apply themselves**, from column names or from a model (principle V, Code Decides, the Model
  Judges, and principle I): a suggestion is a guess; only a person classifies.
- **Commands that edit model source files** (principle II, Read-Only by Default, for the developer commands, which
  change no configuration and no code).
- **Classifying the source pseudo-tables on a model**: they have no model; they stay in the configuration map.

## Assumptions

- "Declared on the model" means a declaration in the model's own PHP source that the package reads without running
  a query. The plan chooses the mechanism (for example a PHP attribute on the class or on properties); no new runtime
  dependency is needed.
- The host's CI runs migrations on a test database before the CI mode, so the table schema exists. m-seq does this
  with MySQL.
- Allowlisted models are the scope (FR-006, owner decision 2026-11-01) because the package already uses that list for `schema.describe`
  and `gate:scan`, and discovering classes from the file system adds a new failure mode.
- Pre-010 rule: no backward-compatibility path is needed; the configuration map keeps its current shape.
- Spec 011's security scope grows to include the merge of model declarations and the configuration map.
