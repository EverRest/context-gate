# Feature Specification: Configuration and Policy Management (MVP)

**Feature Branch**: `007-configuration`

**Created**: 2026-10-31

**Status**: Draft

**Input**: Owner brainstorm "Configuration & Policy Management" (14 groups). This spec takes only the MVP subset
the owner selected. Every item that conflicts with the constitution is excluded here and listed under
Out of Scope with the principle it breaks. Configuration narrows protections; it never widens them (VI).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Run each environment with its own profile (Priority: P1)

An operator sets the application environment (development, staging, production). Each environment has a
profile. A production environment always uses the strict profile and cannot select a weaker one.

**Why this priority**: The same package runs in three places; production must never inherit development
settings by accident.

**Independent Test**: Configuration that selects a weaker profile while `APP_ENV=production`; `gate:check` fails
and names the key.

**Acceptance Scenarios**:

1. **Given** `APP_ENV=production` and no profile set, **When** the gate starts, **Then** the strict profile is used.
2. **Given** `APP_ENV=production` and a profile weaker than strict, **When** `gate:check` runs, **Then** it fails
   and names the profile key.
3. **Given** `APP_ENV=development`, **When** no profile is set, **Then** the strict profile is used as well
   (the default is strict everywhere).

---

### User Story 2 - Choose a security profile within code caps (Priority: P1)

An operator picks a security profile. Two profiles are available: strict (the default) and balanced. A profile
only selects values inside the caps defined in code. It cannot disable a pipeline stage or change a stage order.

**Why this priority**: The profile is the single switch most operators will touch, so it must be safe by default.

**Independent Test**: Switch between strict and balanced; the set of pipeline stages and their order is the same
for both; a limit above its code cap is rejected.

**Acceptance Scenarios**:

1. **Given** no profile is set, **When** a call runs, **Then** the strict values apply.
2. **Given** the balanced profile, **When** a call runs, **Then** each limit is at or below its code cap and every
   stage runs in the same order as under strict.
3. **Given** a limit set above its code cap, **When** `gate:check` runs, **Then** it fails and names the key.

---

### User Story 3 - Enable tools per caller (Priority: P1)

An operator lists which tools each caller may see and call. A tool not listed is hidden. Enabling a tool does not
widen what the tool may return: the field and contract rules from earlier specs still apply.

**Why this priority**: Tool permissions are the main way to narrow what an assistant sees.

**Independent Test**: Two callers with different tool lists; each sees only its own tools, and an unlisted tool
answers as an unknown tool.

**Acceptance Scenarios**:

1. **Given** caller A lists tool X and caller B does not, **When** B lists tools, **Then** X is absent.
2. **Given** caller B calls tool X, **When** the call runs, **Then** the response is identical to a call to an
   unknown tool and the receipt records `policy.hidden`.

---

### User Story 4 - Set limits for connectors, caching and rate (Priority: P2)

An operator sets timeouts, cache TTL, maximum response size and rate limits per connector and per tool. Every
value can only be lower than the code cap. Connector access is read-only in this MVP.

**Why this priority**: These limits protect the application and bound what one call can return.

**Independent Test**: A timeout above its cap fails `gate:check`; a lower timeout applies to the next call.

**Acceptance Scenarios**:

1. **Given** a timeout lower than its cap, **When** a call runs, **Then** the lower value applies.
2. **Given** a connector set to write access, **When** `gate:check` runs, **Then** it fails: write access is not
   available in this MVP.
3. **Given** a cache TTL above its cap, **When** `gate:check` runs, **Then** it fails and names the key.

---

### User Story 5 - Control logging and privacy (Priority: P2)

An operator chooses the log level and whether request metadata is logged. Secret and personal-data redaction is
always on and cannot be turned off. Logs never hold argument or result values.

**Why this priority**: Logs are the most common place where data leaks after a call.

**Independent Test**: Attempt to disable redaction; `gate:check` fails. Run a call with a known value; the log
line does not contain the value.

**Acceptance Scenarios**:

1. **Given** a configuration that disables redaction, **When** `gate:check` runs, **Then** it fails and names the key.
2. **Given** a call with a sentinel value in its arguments, **When** the receipt is written, **Then** the sentinel
   does not appear in the log.
3. **Given** any configuration change, **When** it is applied, **Then** an audit line records the changed keys and
   a hash of the old and new values, and no value.

---

### User Story 6 - Validate, export, import and roll back (Priority: P2)

An operator validates a configuration, exports it without secrets, imports it, and rolls back to an earlier
version. Import validates the whole file before anything changes. Each applied version has a hash.

**Why this priority**: Operators must be able to move a configuration between environments and undo a bad change.

**Independent Test**: Import an invalid file; nothing changes. Import a valid file, roll back; the earlier version
is active again.

**Acceptance Scenarios**:

1. **Given** an invalid file, **When** import runs, **Then** it fails, reports every error, and the active
   configuration is unchanged.
2. **Given** an export, **When** it is inspected, **Then** it contains no secret values and no fingerprint of a
   secret.
3. **Given** two applied versions, **When** rollback runs to the earlier one, **Then** that version is active and
   its hash is recorded.

---

### User Story 7 - Simulate a decision before applying a rule (Priority: P3)

An operator asks what a call would decide, without running the tool. The simulator reports the decision and reason
code. It writes a receipt marked as simulated and never calls the handler.

**Why this priority**: The simulator lets an operator check a change before it reaches an assistant.

**Independent Test**: Simulate a call to a hidden tool and to a visible tool; the decisions match the real calls.

**Acceptance Scenarios**:

1. **Given** a hidden tool, **When** the simulator runs, **Then** it reports `hidden` and the handler is not called.
2. **Given** a visible tool with valid arguments, **When** the simulator runs, **Then** it reports `allow`.
3. **Given** any simulation, **When** it finishes, **Then** its receipt is marked as simulated.

---

### Edge Cases

- `APP_ENV` is unknown or empty: treat it as production for profile selection.
- A rollback target contains a limit whose code cap has since decreased: rollback fails and names the key.
- An import file sets a key that does not exist: it fails validation; unknown keys are never ignored silently.
- A profile switch during a running server: the switch takes effect at the next start, not mid-session.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Every configured value is checked against its code cap at boot and by `gate:check`. A value above
  its cap fails.
- **FR-002**: The strict profile is the default in every environment.
- **FR-003**: Two security profiles exist: strict and balanced. A profile selects values only within code caps.
- **FR-004**: No profile may disable a pipeline stage or change the stage order.
- **FR-005**: `APP_ENV=production` forces the strict profile. A weaker profile in production fails `gate:check`.
- **FR-006**: Secret and personal-data redaction cannot be disabled by configuration.
- **FR-007**: Logs and audit lines never contain argument or result values. Audit lines record changed keys and
  hashes only.
- **FR-008**: Tool permissions per caller narrow visibility. A permission never widens a field or contract rule.
- **FR-009**: Connector access is read-only. A write setting fails `gate:check`.
- **FR-010**: Timeouts, cache TTL, response size and rate limits may only be lower than their caps.
- **FR-011**: `gate:check` validates the whole configuration and reports every error with its key.
- **FR-012**: Export excludes secret values and fingerprints of secrets.
- **FR-013**: Import validates the whole file first; an invalid file changes nothing.
- **FR-014**: Each applied configuration version has a hash. Rollback restores an earlier version and fails if a
  cap has since decreased below one of its values.
- **FR-015**: The policy simulator reports the decision and reason code without calling the handler, and writes a
  receipt marked as simulated.
- **FR-016**: A caller that lists a tool absent from `tools` makes the settings document invalid, so every call by
  every caller returns `config.invalid` until the list is fixed (research R9). Accepted for MVP; stated in the
  Upgrade notes.
- **FR-017**: The effective values of the active profile are the values the firewall budget and the OpenAPI source
  use at runtime (research R2, tasks T031). A default install (no `profile` set) runs with the strict values.

### Key Entities

- **Environment profile**: the application environment and the security profile it requires.
- **Security profile**: a named set of values inside the code caps (strict, balanced).
- **Configuration version**: an applied configuration, identified by its hash, with its previous version.
- **Audit line**: changed keys, hashes of old and new values, actor and time. No values.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In the test suite, every value above its cap is rejected by `gate:check` (100% of cases).
- **SC-002**: Production never runs with a profile weaker than strict (0 cases in the test suite).
- **SC-003**: For each test call, the simulator decision equals the decision of the real call.
- **SC-004**: An invalid import changes the active configuration in 0 cases.
- **SC-005**: No test log line contains a sentinel argument or result value.

## Out of Scope

Each item below was in the owner brainstorm and is excluded from this spec, with the reason.

- **Permissive profile and default allow** (principle III, Deny by Default).
- **Read + Execute connector mode and any write tool** (principle II, Read-Only by Default; no write tools before
  a later spec).
- **Custom policies that allow a call or lower a protection** (principle VI). Policies that only add restrictions
  may be specified later.
- **AI-assisted configuration that applies changes** (principle V, Code Decides). The package does not run a model
  to set configuration.
- **Authentication None for production** (principle VIII). Authentication is spec 005.
- **Exploratory and other AI behavior modes, search and indexing settings, vector databases, embedding models**:
  no model runs in this package at this stage, and these depend on specs 002 and later.
- **Custom plugins, middleware, providers and webhooks** (principle X, Minimal Dependencies).
- **Redis or other cache backends** beyond the framework cache store (principle X).
- **Settings user interface**: this is a package; configuration is a file and artisan commands.
- **Tool groups, tool risk levels and approval flows**: deferred to a later spec.
- **Presets, a configuration wizard and recommendations**: deferred.

## Upgrade notes

These lines are the release-note wording for this feature (CHANGELOG, Unreleased).

- Strict is the default. The firewall budget does not change on a default install: 16384 bytes per call, 409600 per
  session, 5242880 per day, 60 calls per minute. The OpenAPI fetch and local read change from 5 seconds and 2 MB to
  2.5 seconds and 1 MB. To keep the old values, set `sources.openapi.timeout_seconds` and
  `sources.openapi.max_bytes` (up to their caps).
- `profile` = `balanced` selects the code caps (65536 bytes per call, 2097152 per session, 20971520 per day, 300 calls
  per minute, 5 seconds, 2 MB). Production always runs strict.
- A caller that lists a tool absent from `tools` makes every call by every caller refuse with `config.invalid` until
  the list is fixed. `gate:check` names `callers.<id>.tools`. This is accepted for MVP (FR-016).

## Assumptions

- Configuration remains one file, `config/context-gate.php`, plus `APP_ENV`.
- Caps are defined in code by the specs that own each limit (001, 002, 005).
- Version history is stored as files under the application's storage path, not in the cache (a cache can be
  cleared, and rollback needs the earlier versions) and not in a new database table (principle X).
- Audit lines use the `context-gate` log channel defined in spec 001.

## Amendments (review fixes round 2, 2026-11-01)

- Tool entries accept only `enabled`, `class`, `gate`, `fingerprint` and `contract`; any other key is `config.invalid` and `gate:check` reports it.
- Per-tool `max_bytes` and `calls_per_minute` were never implemented and are not supported; tasks T032 and T033 are dropped.
- `gate:check` verifies the PHP extensions curl, mbstring and intl. A `firewall.ceiling` other than `public` or `internal` fails the check, naming `firewall.ceiling`. Configuration narrows; a `personal` or `secret` ceiling would widen what a caller receives, and a `secret` ceiling serves nothing.
- Receipt rule hashes follow the effective (profile) values.
- Tool names must match `^[A-Za-z0-9_.-]{1,128}$`; boot fails otherwise.
- 2026-11-01: a configured `firewall.ceiling` accepts only `public` and `internal`. `personal` and `secret` are refused with `config.invalid` on the call path, and `gate:check` reports a FAIL naming `firewall.ceiling`.
- 2026-11-01: an unreadable `index.json`, or an active hash whose version file is missing, does not stop boot. The settings are invalid: every call is refused with `config.invalid`, and `gate:check` reports a FAIL naming `versions/index.json` or `versions` without the hash. `gate:config:import` still runs and refuses to overwrite an unreadable index; the operator fixes or removes the file, then imports.
- 2026-11-01 (review 007-REV, F3): a segment is token-like when any underscore-separated part is, so `ghp_` plus a letters-only run is masked; the whole segment with underscores removed is not judged, because that would mask `notification_channels`.
- 2026-11-01 (review 007-REV, F4): import and rollback judge the document as boot will see it, merged over the file config (ConfigOverlay); a tools key must equal the tool's `Tool::name()`, and callers are checked against those names.
- 2026-11-01 (review 007-REV, F5): a re-applied hash moves to the newest position with `previous` set to the version active just before it, so A, B, A rolls back to B; the index is changed under an exclusive `.lock` file in the config directory, and a failed write throws.
- 2026-11-01 (review 007-REV, F6): the audit line is written before the index changes; if it cannot be written, nothing is applied and the error names `channel`.
- 2026-11-01 (review 007-REV, F8): every numeric limit must be at least 1; zero and negative values fail `gate:check` and are refused with `config.invalid`, so a timeout below 1 second is refused too.
- 2026-11-01 (review 007-REV, F9): `config.invalid` refuses `tools/call` only; `tools/list` and the other methods are not gated (unchanged; FR-016 and R8 mean calls).
- 2026-11-01 (review 007-REV, F9b): an explicit limit value up to its cap beats the profile value, and a value above its cap is refused at boot (research R2, data-model EffectiveSettings).
