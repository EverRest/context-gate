# Data Model: MCP stdio Foundation

No database tables in this feature. All state is configuration, in-memory per process, or log output.

## Tool

| Field | Type | Source | Notes |
|---|---|---|---|
| name | string | class `$name` | Unique across the server (FR-004). Duplicate names stop boot. |
| description | string | class `description()` | Included in the fingerprint. |
| schema | JSON Schema object | class `schema()` | Arguments; unknown keys are rejected (R5). |
| policy | Gate ability name | config `tools.<name>.gate` | Checked per call (FR-008). |
| ceiling | enum `public`, `internal`, `personal`, `secret` | config | Used by visibility (FR-004); enforced fully in spec 002. |
| fingerprint | sha256 string | config `tools.<name>.fingerprint` | Approved description hash (FR-009). |
| enabled | bool | config `tools.<name>.enabled` | Default false (FR-003). |

**Validation rules**
- A tool MUST be enabled and have a matching fingerprint to be visible.
- A tool name is matched exactly, case-sensitive (edge case).

## Caller

| Field | Type | Source |
|---|---|---|
| id | string | OS user name for stdio (`get_current_user()`) |
| allowed_tools | list of names | config `callers.<id>.tools` |

Bound in the container at start. Read by `shouldRegister()` and by the policy check.

## Receipt

Written to the `context-gate` log channel as one JSON line per call (D19).

| Field | Type | Notes |
|---|---|---|
| id | string | `r_` plus 8 random hex characters; returned to the caller on failure |
| at | ISO 8601 UTC | |
| caller | string | Caller id |
| tool | string or null | Null when the tool name is unknown |
| decision | `allow`, `hidden`, `denied`, `invalid`, `failed` | |
| reason | reason code or null | See Reason code |
| rules | object | Reserved for spec 002 (D32); empty in this feature |

**Invariant**: no argument value and no result value is ever written (FR-014).

## Reason code

Fixed set in this feature:

| Code | Decision | Caller sees |
|---|---|---|
| `policy.hidden` | hidden | `Tool [name] not found.` (-32602) |
| `tool.pin_mismatch` | hidden | `Tool [name] not found.` (-32602) |
| `input.invalid` | invalid | -32602 naming the argument |
| `policy.denied` | denied | `Request refused.` with `isError: true` |
| `handler.failed` | failed | `Request failed. Reference: r_...` with `isError: true` |

## State transitions

Tool visibility per process:

    configured (enabled=false) -> hidden
    enabled + fingerprint matches -> visible (per caller)
    enabled + fingerprint differs -> hidden (tool.pin_mismatch)
