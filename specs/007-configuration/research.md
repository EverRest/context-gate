# Research: Configuration and Policy Management (MVP)

## R1. Where the applied configuration lives

**Decision**: Import and rollback write versions to `storage_path('app/context-gate/config/versions/<hash>.json')`.
The active version is merged over `config/context-gate.php` at boot by `ConfigOverlay`.

**Rationale**: The file is under version control and holds comments; code must not rewrite it. Storage is writable at
run time and survives cache clears (spec assumption).

**Alternatives rejected**: rewriting the PHP file (unsafe, loses comments, changes a tracked file); a database table
(constitution X, spec assumption); the cache store (can be cleared, spec assumption).

**Impact**: The spec assumption "one file, `config/context-gate.php`, plus `APP_ENV`" changes to "one file plus an
overlay in storage". Needs maintainer decision.

## R2. Strict and balanced values

**Decision** (maintainer, final): Balanced holds the code cap. Strict holds the values the firewall already uses as
its code defaults (`Budget`), and the OpenAPI values on the branch. The stage list is not configurable.

| Key | Cap (code) | Balanced | Strict |
|---|---|---|---|
| `firewall.limits.per_call_bytes` | `Caps::PER_CALL` 65536 | 65536 | 16384 |
| `firewall.limits.per_session_bytes` | `Caps::PER_SESSION` 2097152 | 2097152 | 409600 |
| `firewall.limits.per_day_bytes` | `Caps::PER_DAY` 20971520 | 20971520 | 5242880 |
| `firewall.limits.calls_per_minute` | `Caps::PER_MINUTE` 300 | 300 | 60 |
| `sources.openapi.timeout_seconds` | `Caps::OPENAPI_TIMEOUT` 5.0 | 5.0 | 2.5 |
| `sources.openapi.max_bytes` | `Caps::OPENAPI_BYTES` 2097152 | 2097152 | 1048576 |

**Rationale**: Strict is the default (FR-002). Its firewall values are the ones a default install already gets, so
the default does not receive more than it does today (constitution VI: configuration only narrows). Its OpenAPI
values are lower than the caps, so it narrows without touching stages.

**Behaviour change, OpenAPI only**: the firewall budget does not change on a default install. The URL fetch and the
local read of an OpenAPI document move from 5 seconds and 2 MB (the caps, as shipped) to 2.5 seconds and 1 MB on a
default install. Operators restore the old values with `sources.openapi.timeout_seconds` and
`sources.openapi.max_bytes` (an explicit value up to its cap wins over the profile). Release notes say so (spec
Upgrade notes).

**Wiring** (tasks T031): the values in use come from `EffectiveSettings`, not from the raw config, so `Budget` and
`OpenApiSource` read what the active profile selects. No new config key is added.

Rejected: the higher values first drafted for strict (32768, 1048576, 10485760, 150). They would widen a default
install compared with today.

## R3. Version hash and rollback guard

**Decision**: Hash is sha256 over canonical JSON (sorted keys). Rollback re-validates the stored document against the
caps in force now.

**Rationale**: A version written under an older, higher cap can be above a lowered cap; rollback must refuse it
(FR-014, spec edge case).

## R4. Cache TTL is not a setting in this MVP

**Decision**: Drop the cache TTL story. The only TTL in the package is `Budget`'s session counter TTL
(`GateCallTool::SESSION_TTL` 3600) and pseudonym scopes (`Pseudonymizer`), both fixed.

**Rationale**: A counter TTL is a window. A lower TTL resets the session and daily counters sooner, which widens what
a caller can receive. That breaks constitution VI. No cache exists whose lifetime can be lowered without weakening a
limit.

**Open**: the spec US4 scenario 3 (cache TTL above cap fails) cannot be kept as written. Maintainer to remove it or
name a cache that has no security role.

## R5. Simulator and receipt marking (needs decision first)

**Decision**: Add an optional `simulated` boolean to `Receipt::write`. It appears only when true.

**Rationale**: FR-015 requires the receipt to be marked. Optional fields already exist (`dropped`, `truncated`,
`flags`, `refusal_step`), so the change follows the existing pattern.

**Impact**: Spec 001 receipt format gains one optional field. This is a change to spec 001 (and 002 receipts that
reference it). The change must be decided and written into spec 001 or 002 before implementation (constitution: every
rule change goes into the spec first).

**Scope of the simulator**: visibility, argument validation and policy only. Fetch, projection, redaction and budget
are not simulated. SC-003 applies to the decisions it reports.

## R6. Logging settings

**Decision**:
- `redaction` is not a key. Setting it, or any key that names a stage, is an unknown key with a message that names
  the rule (FR-006, constitution VI).
- `log_level` (`debug|info|notice|warning|error`) is validated. It has no consumer until the exception logger
  exists; it is not applied to receipts.
- The "log request metadata" toggle from the owner brainstorm is not added.

**Rationale**: Constitution IV requires a receipt for every call, including denials. A toggle that drops receipts
would break it. A log level applied to receipts would do the same.

**Impact**: US5 scenario "chooses whether request metadata is logged" is removed from the MVP. Maintainer to confirm.

## R7. Export and audit privacy

**Decision**: Canary values are never exported, never hashed and never named in an audit line beyond the key
`canaries.values` (the key name is not a secret). Other values in audit lines are hashed per key. The export drops
keys matching `/secret|token|password|key|credential/i`, which is an additional guard, not the primary one; the
primary rule is that the schema has no secret-bearing key except canaries.

**Rationale**: A hash of a canary is a fingerprint (FR-012). A hash of a low-entropy enum such as `strict` is guessable,
so the audit hashes are documented as change detection, not as secrecy.

## R8. Boot failure mode

**Decision**: At boot `ActiveSettings` validates the effective document once. When invalid, every call is refused with
`config.invalid` and the receipt records it (existing `ReasonCode::ConfigInvalid`). `gate:check` lists every violation
with its key. Nothing is silently ignored.

**Rationale**: Constitution VII (model sees only a reason code) and VI (a value above cap fails). Refusing the whole
gate on boot would stop operators seeing the error; refusing calls keeps the failure visible in receipts.

**Alternative rejected**: fall back to file defaults silently (hides the operator's intent; fails the spec edge case).

## R9. A caller lists a tool that is not in `tools` (maintainer decision D2)

**Decision** (maintainer, final, accepted for MVP): a caller whose `callers.<id>.tools` names a tool absent from
`tools` makes the settings document invalid. Then every call by every caller returns `config.invalid` until the
list is fixed. `gate:check` names the key `callers.<id>.tools`.

**Rationale**: The document is validated once (R8). One bad grant refuses the whole gate instead of being ignored,
and nothing is silently dropped (constitution VI, edge case "unknown keys are never ignored silently").

**Trade-off**: one operator typo stops every caller, not only the one named. Accepted for MVP. A per-caller refusal
would need a second validity state; it is not added now.

**Release note**: stated in spec 007 Upgrade notes and in CHANGELOG under Unreleased.
