# Data Model: Context Firewall

No tables. State is configuration, the classification catalog file, in-memory per process, and the
application cache (pseudonym keys and counters).

## Classification

| Field | Type | Rule |
|---|---|---|
| table | string | Model table name |
| column | string | Column name |
| class | enum `public`, `internal`, `personal`, `secret` | Exactly one per column (FR-001) |

Ordering for the ceiling: `public` < `internal` < `personal` < `secret`.

## ContextContract

| Field | Type | Rule |
|---|---|---|
| name | string | Unique, used in config and rule hash |
| model | class name | Eloquent model |
| fields | list of Field | Order is preserved in output (FR-002) |

Validation on load (FR-004, FR-006, FR-011):
- every field references a catalog column;
- a `secret` field fails;
- a `personal` field needs a pseudonym, aggregate or bucket form;
- a relation's contract must have no relations.

## Field

| Form | Allowed for | Output |
|---|---|---|
| plain | `public`, `internal` | raw value, NFKC-normalised with invisible characters removed (R9) |
| pseudonym(prefix) | `personal`, `internal` | `prefix_xxxxxxxxxx` |
| bucket(breaks) | numeric | range label |
| date(precision) | date and datetime | truncated date |
| aggregate(count) | any except `secret` | count of matching records (a `secret` field is never returned, in any form, constitution I) |
| text | `internal`, `public` | normalized, redacted, untrusted-wrapped |
| relation(contract) | relation | nested object by that contract |

## PseudonymScope

| Field | Type | Rule |
|---|---|---|
| id | string | `session:<connection id>`, `day:<tool>`, `week:<tool>` |
| key | 32 random bytes | Encrypted in cache when scope is not session (R4) |
| ttl | seconds | Session: until disconnect; day: 86400; week: 604800 |
| reverse | map pseudonym → (table, id) | Same TTL as key |

## Budget counters

| Counter | Key | TTL | Limit default | Cap |
|---|---|---|---|---|
| per call | none (per response) | none | 16 KiB | 64 KiB |
| per session | `budget:session:<id>` | session | 400 KiB | 2 MiB |
| per token day | `budget:day:<caller>:<Y-m-d>` | until 00:00 UTC | 5 MiB | 20 MiB |
| per minute | RateLimiter `gate:<caller>` | 60 s | 60 calls | 300 calls |

## Canary

| Field | Type | Rule |
|---|---|---|
| value | string | From config; never stored in receipts (FR-024) |

## Rule hashes

| Field | Source | Length |
|---|---|---|
| rules.tool | tool description | 6 hex characters |
| rules.contract | canonical contract definition | 6 hex characters |
| rules.classification | canonical catalog | 6 hex characters |

## Receipt (extends spec 001)

Adds `rules` (object, from above), `projection` (`dropped` list of field names with reason), `truncated`
(bool), `flags` (list, e.g. `injection.phrase`).

## Reason codes (ReasonCode, 21 cases)

Every case of `ReasonCode` in `src/Server/ReasonCode.php`. Decision is `ReasonCode::decision()`. Caller sees is the
response `GateCallTool` returns for that code.

| Code | Decision | Caller sees |
|---|---|---|
| `policy.hidden` | hidden | JSON-RPC -32602 `Tool not found.` |
| `tool.pin_mismatch` | hidden | JSON-RPC -32602 `Tool not found.` |
| `input.invalid` | invalid | JSON-RPC -32602 |
| `policy.denied` | denied | `Request refused.` |
| `handler.failed` | failed | `Request failed. Reference: r_...` |
| `pseudonym.unknown` | refused | `Request refused.` |
| `pseudonym.keyfail` | failed | `Request failed. Reference: r_...` |
| `transform.failed` | failed | `Request failed. Reference: r_...` |
| `config.invalid` | refused | `Request refused.` |
| `canary.hit` | blocked | `Request failed. Reference: r_...` |
| `budget.session` | refused | `Request refused.` |
| `budget.daily` | refused | `Request refused.` |
| `rate.limited` | refused | `Request refused.` with retry time in result |
| `source.missing` | failed | `Request failed. Reference: r_...` |
| `schema.missing` | failed | `Request failed. Reference: r_...` |
| `source.refused` | denied | `Request refused.` |
| `egress.denied` | denied | `Request failed. Reference: r_...` |
| `source.redirect` | denied | `Request failed. Reference: r_...` |
| `source.timeout` | failed | `Request failed. Reference: r_...` |
| `source.too_large` | denied | `Request failed. Reference: r_...` |
| `openapi.version` | denied | `Request failed. Reference: r_...` |
| `field.unlisted` | dropped | field absent (not an error) |

A pseudonym key failure (`pseudonym.keyfail`) is `failed`, as in the code and in `contracts/firewall-pipeline.md` step 5.

Receipt-only reasons, written as strings and not `ReasonCode` cases: `field.unclassified` (dropped, field absent),
`ceiling.exceeded` (dropped, field absent), `contract.depth` (load failure, `gate:check` only), `redaction.failed`
(field dropped), `budget.call` (truncated, `truncated: true` in result).
