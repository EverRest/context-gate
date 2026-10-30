# Data Model: Release Readiness

No tables. Results are computed per run.

## CheckResult

| Field | Type | Values |
|---|---|---|
| name | string | Check class name |
| status | enum | `pass`, `fail`, `warn` |
| subject | string | Setting key (`context-gate.limits.per_call_bytes`) or file path (`app/Gate/classification.php`) |
| message | string | No values; names only |
| fix | string | Command or instruction to resolve |

## AuditEntry

| Field | Type | Source |
|---|---|---|
| tool | string | Registry |
| enabled | bool | Config |
| risk | enum `low`, `medium`, `high` | Config `risk` (spec 001 Tool) |
| ceiling | enum `public`, `internal`, `personal` | Config or token ceiling |
| field_classes | map class → count | Contract (spec 002) |
| contract | string | Contract name |
| rules | `{tool, contract, classification}` | Rule hashes (spec 002 R11) |

Tools defined but not enabled appear as `{tool, enabled: false}` with no other field.

## ReleaseState

| Field | Type | Rule |
|---|---|---|
| version | string | `0.1.0` (R7) |
| changelog_heading | bool | present |
| reporting_placeholder | bool | must be false (R8) |
| publish_approved | bool | only from explicit workflow input (R5) |

## Install matrix

| Laravel | PHP | Result |
|---|---|---|
| 12.x | 8.2, 8.3, 8.4 | install, gate:install, gate:check, quickstart |
| 13.x | 8.3, 8.4 | same |
