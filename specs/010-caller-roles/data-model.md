# Data Model: Caller Roles

No new table, no migration (research R2).

## Role (config, value object `ContextGate\Settings\Role`)

| Field | Type | Rule |
|---|---|---|
| `name` | `string` | `^[A-Za-z0-9_-]{1,64}$`, key under `roles` |
| `tools` | `list<string>` | each a key of `tools`; may be empty |
| `ceiling` | `public` \| `internal` \| `personal` | `secret` fails |
| `limits` | map of `per_call_bytes`, `per_session_bytes`, `per_day_bytes`, `calls_per_minute` => `int` | each at most the effective profile value; absent = profile value |
| `scope` | `[]` | reserved for spec 014; any non-empty value fails |

Read only through `ActiveSettings::roles(): array<string, Role>`.

## Stdio role mapping

`stdio_roles`: `array<string, string>` OS user name => role name; role must be defined. Read through
`ActiveSettings::stdioRoles()`.

## Role assignment on a token

| Storage | Value | Written | Changed |
|---|---|---|---|
| `gate_access_tokens.abilities` (Sanctum JSON) | `gate:role:<name>` | once, by `TokenIssuer::issue` | never; revoke and reissue |

Token ability set after this feature: `gate:read`, exactly one `gate:ceiling:<c>`, at most one `gate:role:<name>`.

| Role abilities on a token | Auth result | Call result |
|---|---|---|
| 0 (issued before this feature) | authenticated | no tools; calls `role.unknown` |
| 1, role defined | authenticated | role's tools |
| 1, role not defined | authenticated | no tools; calls `role.unknown` |
| 2 or more | `403`, receipt `token.ability` | — |

## Caller (runtime)

| Field | Type | Source |
|---|---|---|
| `id` | `string` | `token:<id>` (HTTP) or OS user name (stdio) |
| `role` | `?string` | token ability (HTTP) or `stdio_roles` (stdio) |
| `tools` | `list<string>` | role's tools, `[]` when role null or undefined |
| ceiling | `?Ceiling` | narrower of transport ceiling and role ceiling; null when no defined role |

## Reason code

`role.unknown` (`ReasonCode::RoleUnknown`), decision `hidden`: caller with no role or an undefined role.

## Receipt fields

| Receipt | New field | Value |
|---|---|---|
| every call receipt | `role` | role name, or `none` |
| `token.issued` | `role` | role name |
| `rules.classification` | (input change) | includes effective role definition and effective ceiling |
