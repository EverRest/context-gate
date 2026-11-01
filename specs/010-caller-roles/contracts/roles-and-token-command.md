# Contract: Roles config, `gate:token`, `gate:simulate`, receipts

## Config

```php
'roles' => [
    '<name>' => [
        'tools' => ['<tool>', ...],
        'ceiling' => 'public'|'internal'|'personal',
        'limits' => [                        // optional, each <= profile value in force
            'per_call_bytes' => int, 'per_session_bytes' => int,
            'per_day_bytes' => int, 'calls_per_minute' => int,
        ],
        // 'scope' => [],                    // reserved (spec 014); must be absent or empty
    ],
],
'stdio_roles' => ['<os user>' => '<role name>'],
```

`callers` is no longer a setting. `gate:check` fails with subject `context-gate.callers` and message
`callers was replaced by roles; see docs/tokens.md`.

`gate:check` subjects for role errors: `context-gate.roles.<name>`, `roles.<name>.tools`, `roles.<name>.ceiling`,
`roles.<name>.limits.<key>`, `roles.<name>.scope`, `stdio_roles.<user>`.

`gate:check` warning (exit 0 if nothing else fails), one per affected token:
`token <id> (holder <holder>) has role <name|none>, which is not defined; it sees no tools`.

## `gate:token`

```text
gate:token --holder=<name> --issuer=<name> --role=<role> [--ceiling=public|internal|personal] --expires=<N>d
           [--ability=gate:read ...]
gate:token --list [--json]
gate:token --revoke=<id>
```

| Input | Result |
|---|---|
| no `--role` | exit 1, `--role is required.` nothing issued |
| `--role` not defined in config | exit 1, `role <name> is not defined in config/context-gate.php.` nothing issued |
| no `--ceiling` | token ceiling = role ceiling |
| `--ceiling` equal or narrower than role | stored as given |
| `--ceiling` wider than role | exit 1, `--ceiling must not be wider than the role's ceiling (<c>).` nothing issued |
| `--ability=gate:role:*` | rejected like any ability outside `gate:read` and the ceiling ability |

Output on success: the token value once, `Caller key: token:<id>`, `Role: <name>`. No `callers` line.

`--list` columns: `id, holder, issuer, caller, role, ceiling, expires_at, issued_at`; JSON adds `role` (`none` when
the token has no role ability).

## `gate:simulate`

```text
gate:simulate {tool} {role} [--args=<json>]
```

The second argument is a role name (was a `callers` key). Undefined role: exit 1, `role is not defined.`

## Receipts

Every call receipt: `"role": "<name>"` or `"role": "none"`. New reason `role.unknown` (decision `hidden`).
`token.issued`: adds `"role": "<name>"`.
