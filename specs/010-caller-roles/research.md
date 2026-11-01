# Research: Caller Roles

State checked at HEAD `d744441` (2026-11-01). Assumes spec 009 is implemented (`ActiveSettings` typed getters,
`RuleHashes`); file references to 009 classes are to the 009 plan.

## R1. Role config shape

**Decision**:

```php
'roles' => [
    'support' => [
        'tools' => ['routes.list', 'docs.read'],
        'ceiling' => 'internal',
        'limits' => ['calls_per_minute' => 30],   // optional; per_call_bytes, per_session_bytes, per_day_bytes, calls_per_minute
        // 'scope' => [],                         // reserved for spec 014; absent or [] only
    ],
],

'stdio_roles' => [
    'dev' => 'developer',                          // OS user name => role name
],
```

Validation (in `SettingsValidator`, keys named as in the spec):

| Key | Rule |
|---|---|
| `roles.<name>` | name matches `^[A-Za-z0-9_-]{1,64}$` |
| `roles.<name>.tools` | `list<string+>`, each in `tools` |
| `roles.<name>.ceiling` | `enum:public\|internal\|personal`; `secret` has its own message |
| `roles.<name>.limits.<key>` | `int?`, at most the effective profile value for that key (so at most the cap) |
| `roles.<name>.scope` | absent or empty array; anything else: "reserved for row scopes (spec 014)" |
| `stdio_roles.<user>` | `string+`; the role must be defined |
| `callers` | any presence fails: "callers was replaced by roles; see docs/tokens.md" (FR-015) |

**Rationale**: one section per concept; the stdio mapping is flat (user => role) because a user has exactly one role.
Unknown keys under a role fail like every other section (spec 007 rule).

**Alternatives rejected**: `stdio.users` nested section (one more level for one map); OS user listed inside each role
(`users: [...]`), which lets one user appear in two roles and needs a conflict rule.

## R2. Where the role is stored on the token

**Decision**: a Sanctum ability `gate:role:<name>`, written by `TokenIssuer::issue` in the same `createToken` call as
`gate:read` and the ceiling ability. **No migration.**

**Rationale**:
- Written once, at issuance, in the existing transaction; no command edits a token's abilities (FR-007).
- `GateTokenAuth` already parses the ability list and enforces "exactly one ceiling"; the role uses the same rule:
  at most one `gate:role:*` ability. Zero role abilities (tokens issued before this feature) is not an auth error:
  the token authenticates and the call is denied with `role.unknown` (US3 scenario 4). Two or more is `403
  token.ability`, like two ceilings.
- `Ability::isPackageAbility()` is the single function used by both `GateTokenAuth` and `AbilitySetCheck`; it gains
  one clause: `gate:role:` followed by a valid role name. A role ability with an invalid name stays outside the package
  set.
- A package migration would force every host to publish and run a new migration for a column the ability list
  already expresses.

**Alternatives rejected**: a `role` column on `gate_access_tokens` (migration, host must re-publish migrations);
`gate_token_issuers.role` (that row outlives the token as an audit record, and the role is a property of the
token); the token `name` (that is the holder name, shown in lists).

## R3. How roles replace `callers` and `TokenCallersCheck`

- `Caller` gains `?string $role` (null = no role). `Caller::current()`:
  - HTTP: `GateTokenAuth` stores the role name (or null) as request attribute `context_gate.role`; `GateServer::
    bindTokenContext()` binds it as `context-gate.role` next to the ceiling.
  - stdio: `ActiveSettings::stdioRoles()[<os user>] ?? null`.
  - Tools: `roles()[<role>]->tools` when the role is defined; otherwise `[]`.
- `PolicyStep` (visibility) records `ReasonCode::RoleUnknown` (`role.unknown`, decision `hidden`) instead of
  `policy.hidden` when the caller's role is null or undefined; the response is the unknown-tool response (FR-010).
  `GateListTools` returns no tools for such a caller (it already filters by `$caller->tools`).
- `src/Readiness/Checks/TokenCallersCheck.php` is replaced by `src/Readiness/Checks/TokenRolesCheck.php`: for each
  active token, warn when it has no role ability or names an undefined role, naming token id, holder and role
  (`none` when absent); never the token value. Registered in `CheckRegistry` in place of the old check.
- `gate:token`: `--role` required; prints no `callers` line; `--list` gains a `role` column and JSON field.
- `gate:simulate {tool} {caller}` checked `callers` keys. It becomes `gate:simulate {tool} {role}`: the simulation
  runs as an anonymous caller (`simulate`) holding that role. An undefined role exits 1.
- `SettingsSchema`: `callers` removed; `roles`, `stdio_roles` added. `SettingsValidator` callers cross-check becomes
  the roles cross-check.

## R4. Ceiling resolution order

Per clarification and FR-004/FR-006:

1. **Issuance** (`gate:token`): role must be defined. `--ceiling` absent: token ceiling = role ceiling at that time.
   `--ceiling` present: must be equal to or narrower than the role ceiling, else exit 1, nothing issued. The token
   always stores exactly one ceiling ability (principle VIII).
2. **Per call**: transport ceiling = token ceiling (HTTP) or `firewall.ceiling` (stdio, `public|internal`).
   Effective ceiling = the narrower of the transport ceiling and the role ceiling (order `public < internal <
   personal`). Role undefined or missing: no effective ceiling, `Caller::ceiling()` returns null, which already fails
   closed as `config.invalid` if any path reaches it (it should not: the call is denied at visibility first).
3. A later config edit that widens the role never widens an existing token (step 2 takes the minimum); one that
   narrows it applies at once.

Implemented in `Caller::ceiling()` with a `Ceiling::narrower(Ceiling $a, Ceiling $b)` helper on
`ContextGate\Classification\Ceiling` (or `Token\Ceiling`, whichever `Caller` already returns).

## R5. Per-role limits

`Budget`'s constructor already accepts optional `perCall`, `perSession`, `perDay`, `perMinute` overrides
(`src/Pipeline/Budget.php`). `GateCallTool::budget()` passes the role's limits. Counters stay keyed by caller id, so
two tokens with one role have separate counters (US5 scenario 2). Validation guarantees a role value is at most the
profile value in force, so no runtime `min()` is needed (009 FR-007).

## R6. Receipts and rule hashes

- `Receipt::write` gains `string $role = 'none'`, always written as `role` (SC-004: every receipt has it, including
  HTTP refusals from `RefusalLedger`, which run before a role is known and write `none`).
- `token.issued` receipt adds `role`.
- `RuleHashes::forCall(?Ceiling $ceiling, ?Role $role)`: the classification hash input adds the effective role
  definition (tools sorted, ceiling, limits, scope) and uses the effective ceiling (R4). `none` when no role.

## R7. Dependencies on 009 and 014

- 009: roles are read through `ActiveSettings::roles()` / `stdioRoles()`, validated in the one validator, hashed by
  `RuleHashes`. 010 cannot start before 009 Phase 2 and 009 US4.
- 014 (row scopes, clarified 2026-11-01): scope values are resolved by the host per call and never stored in a role or
  token. The `scope` key is reserved and must stay empty; roles carry **no** scope values. If 014 never needs the
  slot, removing it later is a narrowing change.

## R8. Compatibility

The spec says "no users before version 010" and removes `callers` outright. `CHANGELOG.md` now dates a `[0.1.0]`
release (2026-11-01) that documents `callers`. If 0.1.0 is published, removing `callers` is a breaking change for its
users; the spec's assumption needs the owner's confirmation (flagged; no compatibility layer is planned).
