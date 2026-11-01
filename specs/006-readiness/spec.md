# Feature Specification: Release Readiness

**Feature Branch**: `006-readiness`

**Created**: 2026-10-30

**Status**: Draft

**Input**: Owner brainstorm (roadmap milestone M6): before the first release, the operator must be able to see
what is exposed, verify that the installation is safe, read the documentation, and install the package on the
two supported Laravel versions. The release itself is prepared here but published only after a separate
approval.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See everything that is exposed (Priority: P1)

An operator runs an audit before a release or a configuration change. The report lists every enabled tool, its
risk level, its ceiling, the classes of the fields it returns, its contract and rule hashes, and tools that are
defined but not enabled. The audit changes nothing and shows no values.

**Why this priority**: The operator's first question is "what can the assistant see right now?", and the
answer must be complete.

**Independent Test**: Configuration with two enabled tools and one defined but disabled tool; the report lists
exactly these, with classes and hashes, and no value.

**Acceptance Scenarios**:

1. **Given** two enabled tools, **When** the audit runs, **Then** the report lists both with risk, ceiling, field
   classes, contract name and rule hashes.
2. **Given** a tool defined in configuration but not enabled, **When** the audit runs, **Then** it appears under
   "not enabled" and nothing else about it is shown.
3. **Given** the `--json` option, **When** the audit runs, **Then** the output is valid JSON with the same content.
4. **Given** the audit runs, **When** project files and the database are compared before and after, **Then**
   nothing changed.

---

### User Story 2 - Check the installation before it serves traffic (Priority: P1)

An operator runs the doctor command. It checks the configuration, the classification catalog, the contracts, the
canary setup, the required PHP extensions and packages, the Origin allowlist, the token table, the ability set
and the stdio production rule. It exits with a non-zero code when any check fails, and each failure names the
setting or file to fix.

**Why this priority**: Every rule in the earlier specs is enforced at runtime, but an operator also needs one
command that proves the whole installation is consistent before deployment.

**Independent Test**: Introduce one error per check in turn (an unclassified column, a cap exceeded, a missing
extension, an empty token table); each run exits non-zero and names the item.

**Acceptance Scenarios**:

1. **Given** a correct installation, **When** the doctor runs, **Then** every check passes and the exit code is 0.
2. **Given** a configured value above its hard cap, **When** the doctor runs, **Then** it fails and names the key.
3. **Given** a catalog draft that still contains `// needs decision`, **When** the doctor runs, **Then** it fails
   and names the file and line.
4. **Given** canaries are enabled and a canary value is missing, **When** the doctor runs, **Then** it fails and
   names the setting.
5. **Given** the production environment and stdio configured as the only transport, **When** the doctor runs,
   **Then** it fails with the production rule.
6. **Given** an Origin allowlist entry that is not an exact origin (for example with a path), **When** the doctor
   runs, **Then** it fails and names the entry.

---

### User Story 3 - Read the documentation for each audience (Priority: P2)

A developer, an operator and a security reviewer each find the answers they need in the documentation: how to
install, every configuration key, how to issue, list and revoke tokens, how the privacy model works, and how to
report a security problem.

**Why this priority**: Without documentation the safe defaults are unused and the first release is misconfigured.

**Independent Test**: Each documented command in the docs exists and runs; each configuration key in the docs
exists in the config file; SECURITY.md contains a reporting address placeholder that the owner fills in.

**Acceptance Scenarios**:

1. **Given** the installation guide, **When** a reader follows it on a fresh application, **Then** the install
   succeeds and the doctor passes.
2. **Given** the configuration reference, **When** it is compared with the shipped config file, **Then** every key
   documented exists and every key in the file is documented.
3. **Given** SECURITY.md, **When** a reader looks for how to report, **Then** the reporting channel and expected
   response time are stated.

---

### User Story 4 - Install on Laravel 12 and 13 (Priority: P2)

The package installs on a fresh Laravel 12 application and on a fresh Laravel 13 application, and on both the
install command, the doctor and the first-day quickstart succeed.

**Why this priority**: Supported versions must be proven, not assumed.

**Independent Test**: A scripted install on each version; the three steps pass on both.

**Acceptance Scenarios**:

1. **Given** a fresh Laravel 12 application, **When** the package is installed, **Then** `gate:install` and
   `gate:check` pass and the spec 001 quickstart returns an empty tool list.
2. **Given** a fresh Laravel 13 application, **When** the same steps run, **Then** they pass.
3. **Given** a version outside the supported range, **When** the package is installed, **Then** Composer refuses
   with a version conflict.

---

### User Story 5 - Prepare the release without publishing it (Priority: P3)

The maintainer prepares version 0.1.0: a changelog entry, a version constant, and a release workflow that builds
the archive and checks the changelog. Publication to Packagist and the GitHub release happens only after the
maintainer approves it separately.

**Why this priority**: The release steps are visible and reversible until approval; publishing is not.

**Independent Test**: The release workflow runs in dry-run mode and produces the archive; the changelog guard
fails when the version heading is missing.

**Acceptance Scenarios**:

1. **Given** version 0.1.0 is prepared, **When** the release check runs, **Then** the changelog contains a 0.1.0
   heading and the version constant matches.
2. **Given** the changelog lacks the heading, **When** the release check runs, **Then** it fails.
3. **Given** the release is not approved, **When** the workflow runs, **Then** it builds the archive but does not
   publish it.

---

### Edge Cases

- The audit runs with no enabled tools: the report says so and lists the defined-but-disabled tools only.
- The doctor runs without a database connection: schema checks report `schema.missing` as a warning, and the
  doctor exits non-zero only if the schema is required for an enabled tool.
- The doctor runs on an application that has never run `gate:install`: it fails with the install command as the fix.
- A canary configured but set to an empty value: treated as missing.
- A token table exists but is empty: passes (no tokens is a valid state); the doctor says so.
- The audit on a catalog with a draft marker: lists the column as `draft` and the doctor fails on it.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `gate:audit` MUST list every enabled tool with risk, ceiling, field classes, contract name and rule
  hashes (specs 002, 005).
- **FR-002**: `gate:audit` MUST list tools defined but not enabled, showing only their names.
- **FR-003**: `gate:audit --json` MUST produce valid JSON with the same content as the text output.
- **FR-004**: `gate:audit` MUST NOT display any value, token, canary or secret, and MUST NOT write any file.
- **FR-005**: `gate:check` MUST validate the configuration, including that every configured limit is at most its hard
  cap (spec 002 FR-022).
- **FR-006**: `gate:check` MUST verify that the classification catalog covers every column referenced by a contract
  and contains no draft marker (`// suggested`, `// needs decision`).
- **FR-007**: `gate:check` MUST verify contract depth and that no contract lists a `secret` field.
- **FR-008**: When canaries are enabled, `gate:check` MUST verify that every configured canary value is non-empty.
- **FR-009**: `gate:check` MUST verify the presence of `ext-intl` and `symfony/yaml`.
- **FR-010**: `gate:check` MUST verify that each Origin allowlist entry is an exact origin (scheme, host, optional
  port, no path).
- **FR-011**: `gate:check` MUST verify that the token table exists and that the package ability set is the one
  defined in spec 005.
- **FR-012**: `gate:check` MUST fail when the application is in production and stdio is the only configured
  transport, and MUST pass when HTTP is configured (spec 005, FR-013).
- **FR-013**: `gate:check` MUST exit with code 0 only when every check passes, and MUST name the setting or file
  for every failure.
- **FR-014**: `gate:check` MUST NOT print any value from configuration, catalog, documents or database.
- **FR-015**: The documentation MUST contain an installation guide, a configuration reference listing every key in
  the shipped config file, a token operations guide, a privacy model, and `SECURITY.md`.
- **FR-016**: `SECURITY.md` MUST describe the threat model, the scope of the package, the supported versions and
  the reporting process with a response time.
- **FR-017**: The package MUST install on Laravel 12 and Laravel 13 and fail installation on other versions by
  Composer constraint.
- **FR-018**: The changelog MUST contain an entry for 0.1.0 and the version constant MUST match it.
- **FR-019**: The release workflow MUST build the archive with the package name in its file name and MUST fail when
  the changelog lacks the version heading.
- **FR-020**: No publication to Packagist or a GitHub release MUST happen without a separate approval recorded by
  the maintainer.

### Key Entities

- **Audit entry**: tool name, risk, ceiling, field classes, contract name, rule hashes, enabled flag.
- **Check result**: check name, status (pass, fail, warning), named setting or file, fix hint.
- **Release**: version, changelog entry, archive, approval state.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In 100% of tests, the audit lists every enabled tool and no value.
- **SC-002**: In 100% of tests, each seeded configuration error makes the doctor exit non-zero and name the item.
- **SC-003**: In 100% of tests on a correct installation, the doctor exits 0.
- **SC-004**: In 100% of tests, every configuration key in the reference is in the shipped file and the reverse.
- **SC-005**: The install-and-check run succeeds on a fresh Laravel 12 and a fresh Laravel 13 application.
- **SC-006**: The release check fails in 100% of tests where the changelog heading is missing, and passes when present.
- **SC-007**: A new operator reaches a passing doctor within 30 minutes using the installation guide alone.

## Assumptions

- Specs 001–005 are complete; all rules referenced here exist and are tested there.
- The doctor warns, rather than fails, for a missing database connection unless an enabled tool needs the schema.
- The reporting channel in SECURITY.md is a placeholder address that the owner fills in before the release; the release
  check fails while the placeholder remains.
- Publication to Packagist and a GitHub release are the owner's action after this spec is done.
- The release workflow is adapted from the watcher repository's workflow (archive name and changelog guard) and
  does not publish without an explicit approval input.
- Laravel 12 and 13 are the supported versions; the Composer constraint reflects them.

## Amendments (batch 3, 2026-11-01)

- `gate:scan --json` prints a pure JSON object to stdout and writes no file; `--output=<path>` writes the same JSON
  (directories created with mode 0755). `gate:scan` reports `Models: 0 (...)` when no models are configured and still
  scans routes.
- `gate:token --list --json` prints an array (`id`, `holder`, `issuer`, `ceiling`, `expires_at`, `issued_at`, `caller`;
  never a token value). `gate:preview --json` prints `{text, truncated, dropped:[{field, reason}]}`.
- Under `--json`, errors go to stderr and stdout stays empty; `gate:check --json` still prints valid JSON and exits `1`
  on failure.
- `gate:check` verifies the catalog entry for `openapi.endpoints`.
