# Feature Specification: Caller Roles

**Feature Branch**: `010-caller-roles`

**Created**: 2026-11-01

**Status**: Clarified

**Input**: Owner request: "improvements the package needs: Redis as the main cache store with real concurrency tests;
one path to settings; caller roles instead of token ids". This spec takes the third item. Specs 008 (Redis cache
store) and 009 (settings single source) take the others.

## Context

Today a caller's tools are listed under `callers`, keyed by `token:<id>` for HTTP or by the OS user name for stdio.
A new HTTP token sees no tools until someone adds its id to the config file and redeploys. `gate:token` prints the
line to paste, and `gate:check` warns about active tokens with no entry. Token ids are not known before issuance, so
every new token means a config change and a deploy, and the config file fills with ids nobody can read.

This spec replaces `callers` with roles. A role names what a kind of caller may do. A token is issued with a role,
and needs no config change. There are no users before version 010, so `callers` is removed outright, with no
compatibility layer.

## Clarifications

### Session 2026-11-01

- Q: Does `--ceiling` stay required as today, or become optional and default to the role's ceiling at issuance? (FR-006) → A: Optional when `--role` is given; defaults to the role's ceiling at issuance. The token row always stores a ceiling (principle VIII holds). An explicit `--ceiling` may only be equal to or narrower than the role's.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Issue a token that works at once (Priority: P1)

An operator defines a role `support` in the config file with the tools it may use. Later they issue a token with
`gate:token --role=support`. The token can list and call those tools at once. No config change, no deploy.

**Why this priority**: This is the problem the owner named. Each token today costs a deploy.

**Independent Test**: Define one role; issue a token with it; list tools over HTTP; the role's tools appear.

**Acceptance Scenarios**:

1. **Given** a role `support` with tools X and Y, **When** a token issued with `--role=support` lists tools,
   **Then** X and Y appear and nothing else.
2. **Given** that token, **When** it calls a tool outside its role, **Then** the response is identical to a call to an
   unknown tool and the receipt records `policy.hidden`.
3. **Given** `gate:token` without `--role`, or with a role that is not defined, **When** it runs, **Then** it fails
   with exit 1 and issues nothing.
4. **Given** a token is issued, **When** the `token.issued` receipt is written, **Then** it records the role name.
5. **Given** `gate:token --role=support` without `--ceiling`, **When** it runs, **Then** the token row stores the
   role's ceiling as the token ceiling.
6. **Given** `gate:token --role=support --ceiling=<wider than the role's ceiling>`, **When** it runs, **Then** it fails
   with exit 1 and issues nothing; a ceiling equal to or narrower than the role's is stored as given.

---

### User Story 2 - A role can only narrow a token (Priority: P1)

A role has a ceiling. A call runs with the narrower of the token's ceiling and the role's ceiling. A role with a wider
ceiling than its token never widens what that token receives.

**Why this priority**: Roles are configuration, and configuration narrows, never widens (principle VI). A token's
ceiling is set at issuance and recorded; a later config edit must not raise it.

**Independent Test**: A token with ceiling `internal` and a role with ceiling `personal`; a personal field is not
returned. A token with ceiling `personal` and a role with ceiling `public`; only public fields are returned.

**Acceptance Scenarios**:

1. **Given** a token ceiling `internal` and a role ceiling `personal`, **When** a tool returns a personal field,
   **Then** the field is dropped as for an `internal` caller.
2. **Given** a token ceiling `personal` and a role ceiling `public`, **When** a tool runs, **Then** only public fields
   are returned.
3. **Given** a role with a ceiling `secret`, **When** `gate:check` runs, **Then** it fails and names
   `roles.<name>.ceiling`.

---

### User Story 3 - Unknown or missing role: deny (Priority: P1)

A role is removed from the config file while tokens with that role are still active. Those tokens see no tools. Their
calls answer as unknown tools. The receipt says why.

**Why this priority**: Deny by default (principle III). A token must never fall back to some other role or to all
tools.

**Independent Test**: Issue a token with role `support`, remove the role from config, list and call tools; the list
is empty and the call answers as an unknown tool, with `role.unknown` in the receipt.

**Acceptance Scenarios**:

1. **Given** a token whose role is not defined in config, **When** it lists tools, **Then** the list is empty.
2. **Given** the same token, **When** it calls any tool, **Then** the response equals the unknown-tool response and the
   receipt records `role.unknown`.
3. **Given** an active token whose role is not defined, **When** `gate:check` runs, **Then** it warns, naming the token
   id, its holder and the role name, never the token value.
4. **Given** a token with no role (issued before this feature), **When** it calls a tool, **Then** it is treated as an
   unknown role.

---

### User Story 4 - stdio callers use roles too (Priority: P2)

A developer runs the gate over stdio on a development machine. The config maps their OS user name to a role. They get
that role's tools and ceiling. An OS user that is not mapped gets no tools.

**Why this priority**: One model for both transports. Without it, `callers` would have to stay for stdio.

**Independent Test**: Map OS user `dev` to role `developer`; start stdio as `dev`; the role's tools appear. Start it
as another user; no tools appear.

**Acceptance Scenarios**:

1. **Given** OS user `dev` mapped to role `developer`, **When** stdio lists tools, **Then** the role's tools appear.
2. **Given** an OS user that is not mapped, **When** stdio lists tools, **Then** the list is empty and a call records
   `role.unknown`.
3. **Given** a stdio caller, **When** a call runs, **Then** the ceiling is the narrower of the configured stdio ceiling
   (`firewall.ceiling`) and the role's ceiling.

---

### User Story 5 - Per-role limits (Priority: P2)

An operator gives a role lower limits than the profile, for example fewer calls per minute for a batch role. Each
caller of that role gets the lower limits. A role cannot raise a limit above the profile value in force.

**Why this priority**: Different kinds of callers need different bounds; today the only knob is the global profile.

**Independent Test**: A role with `calls_per_minute` lower than the profile; a caller of that role is refused with
`rate.limited` after that many calls. A role value above the profile fails `gate:check`.

**Acceptance Scenarios**:

1. **Given** a role with a lower `calls_per_minute`, **When** a caller of that role exceeds it, **Then** the call is
   refused with `rate.limited`.
2. **Given** two tokens with the same role, **When** both call tools, **Then** each has its own counters; a role limit
   is per caller, not a shared pool.
3. **Given** a role limit above the profile value in force, **When** `gate:check` runs, **Then** it fails and names
   `roles.<name>.limits.<key>`.

---

### User Story 6 - Receipts say which role applied (Priority: P2)

An auditor reads a receipt and sees the caller id and the role name. The rule hash covers the role definition, so a
change to a role changes the hash.

**Why this priority**: Without the role in the receipt, an auditor cannot tell which rules allowed a response.

**Independent Test**: Two calls by tokens with different roles; receipts carry the role names. Change the role's tool
list; the rule hash changes.

**Acceptance Scenarios**:

1. **Given** any call, including a refusal, **When** the receipt is written, **Then** it records the role name, or
   `none` when no role applies.
2. **Given** a change to a role's tools, ceiling, limits or scope attributes, **When** the next call runs, **Then** its
   rule hashes differ from those before the change.

---

### Edge Cases

- A role lists a tool that is not in `tools`: validation fails and names `roles.<name>.tools` (same rule as spec 007
  FR-016 for `callers`).
- A role is renamed in config: tokens with the old name now have an unknown role and are denied; `gate:check` warns.
  The operator reissues the tokens.
- A role's tool list becomes empty: callers of that role see no tools; this is valid and not a warning.
- A role name that is not a plain identifier (spaces, dots, colons): validation fails.
- A token's role is changed: not possible; revoke and reissue (FR-007).
- The per-tool `gate` ability (host Gate) still applies after the role: a role makes a tool visible; it never skips
  the tool's own authorization.
- An HTTP token is also listed under the stdio mapping by name: the transports are separate; an HTTP call always uses
  the token's role.
- Scope attributes are set on a role before spec 014 is implemented: validation fails (unknown keys are never
  ignored), see FR-012.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The settings document MUST hold a `roles` section. Each role has a name, a list of tools, a ceiling,
  optional limits, and optional scope attributes (FR-012). Roles are validated with the rest of the document (spec
  009) and `gate:check` reports every error with its key.
- **FR-002**: Role names MUST match a plain identifier pattern (letters, digits, `_` and `-`, at most 64 characters).
- **FR-003**: A role's tools MUST be names in `tools`. A role that lists an unknown tool fails validation.
- **FR-004**: A role's ceiling MUST be one of `public`, `internal`, `personal`. `secret` fails validation. The ceiling
  of a call MUST be the narrower of the role's ceiling and the token's ceiling (HTTP) or the configured
  `firewall.ceiling` (stdio).
- **FR-005**: Role limits MAY set `per_call_bytes`, `per_session_bytes`, `per_day_bytes` and `calls_per_minute`. Each
  MUST be at or below the profile value in force (and so below its code cap). A higher value fails validation. Limits
  stay counted per caller id.
- **FR-006**: `gate:token` MUST require `--role=<name>` and MUST refuse a role that is not defined in the current
  config. `--ceiling` is OPTIONAL when `--role` is given: it defaults to the role's ceiling at issuance. The token row
  always stores a ceiling (principle VIII). An explicit `--ceiling` MAY only be equal to or narrower than the role's
  ceiling; a wider one makes `gate:token` fail with exit 1 and issue nothing.
- **FR-007**: The role name MUST be stored with the token at issuance and MUST NOT change afterwards. Changing a
  caller's role means revoking the token and issuing a new one. No command edits a token's role.
- **FR-008**: Role definitions MUST live in config only. They MUST NOT be stored in the database.
- **FR-009**: The stdio transport MUST map OS user names to roles in config. An OS user with no mapping has no role.
- **FR-010**: A caller with no role, or with a role that is not defined, MUST see no tools, and every call MUST answer
  as an unknown tool. The receipt MUST record the new reason code `role.unknown`. There is no default role.
- **FR-011**: `gate:check` MUST warn, without failing, for each active token whose role is missing or not defined,
  naming the token id, the holder and the role name, never the token value. This replaces the current check for
  tokens without a `callers` entry.
- **FR-012**: A role MAY hold scope attributes for spec 014 (row scopes). This spec reserves the key, includes it in
  validation and in the rule hash, and gives it no meaning. Until spec 014 defines its content, any non-empty value
  fails validation. (Spec 014, clarified 2026-11-01, resolves scope values in
  the host on every call and never stores them in a role or token; this key may stay unused.)
- **FR-013**: Every receipt MUST record the role name of the caller, or `none`. The `token.issued` receipt MUST record
  the role name. `gate:token --list` MUST show the role of each token.
- **FR-014**: The rule hashes MUST cover the effective role definition used by the call (tools, ceiling, limits, scope
  attributes), and the effective ceiling of the call (extends spec 009 FR-012).
- **FR-015**: The `callers` setting MUST be removed. A document that still has `callers` fails validation, and the
  message points to `roles`. `gate:token` MUST no longer print a `callers` line to paste.
- **FR-016**: `docs/tokens.md` and `docs/configuration.md` MUST describe roles, `--role`, the stdio mapping, the
  ceiling rule, per-role limits, and the removal of `callers`.

### Key Entities

- **Role**: a named set of tools, a ceiling, optional limits and optional scope attributes. Defined in config.
- **Role assignment**: the role name stored with a token at issuance (HTTP), or the config mapping from an OS user
  name to a role (stdio).
- **Effective ceiling**: the narrower of the role ceiling and the token or stdio ceiling.
- **Scope attributes**: a reserved part of a role, defined by spec 014 (row scopes).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A token issued with a defined role can list and call its role's tools with 0 config changes after
  issuance.
- **SC-002**: In every test pair of token ceiling and role ceiling, the call's ceiling is the narrower one (100%).
- **SC-003**: A caller with a missing or unknown role sees 0 tools and gets the unknown-tool response on 100% of
  calls.
- **SC-004**: 100% of receipts in the test suite carry a role name or `none`.
- **SC-005**: Every role value above its cap or profile value is rejected by `gate:check` (100% of cases).
- **SC-006**: A change to any part of a role definition changes the rule hash (100% of test cases).

## Out of Scope

- **Role definitions stored in the database or edited at runtime** (principle VI and spec 007: configuration is a
  reviewed file, validated by `gate:check` and hashed into receipts; a runtime edit path would be a write path into the
  gate's own policy, outside that review, and principle II keeps the MVP read-only).
- **Changing a token's role after issuance** (principle VIII: issuance is recorded in a receipt; an edit would need its
  own audited write path. Revoke and reissue is already audited).
- **A default role, or a role that inherits all tools** (principle III, Deny by Default).
- **A role ceiling of `secret`, or a role that raises a limit above the profile** (principle VI).
- **Several roles per token, or role inheritance** (principle III: one visible rule per caller is easier to audit;
  no consumer asked for composition).
- **A compatibility layer for `callers`**: there are no users before version 010.
- **The meaning of scope attributes**: spec 014 (row scopes).
- **Role-based write tools** (principle II).

## Assumptions

- No users exist before version 010, so `callers` is removed and tokens issued before this feature are treated as
  having no role (they must be reissued).
- Role assignment is stored with the token row, because token rows already live in the database and the role must be
  known before the config is read for that token. The storage form (an ability such as `gate:role:<name>` or a column)
  is a plan decision; either way it is written once, at issuance.
- The stdio mapping is keyed by OS user name, as `callers` is today.
- Role limits are per caller, using the existing per-caller counters (spec 008 key families); a role adds no new
  counter.
- One role per token.
- `firewall.ceiling` keeps its meaning as the stdio ceiling (spec 007 amendment: `public` or `internal` only).
- This spec depends on spec 009 (roles are read and validated through the one settings path, and the rule hash uses
  effective values). Spec 014 (row scopes) depends on this spec for the scope attributes slot.
