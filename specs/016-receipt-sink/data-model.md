# Data Model: Durable Receipt Sinks and Operator Metrics

## Receipt record (all sinks)

| Field | Type | Present | Notes |
|---|---|---|---|
| `id` | string | always | `r_` + 24 hex (96 random bits) |
| `at` | string | always | UTC `Y-m-d\TH:i:s\Z` |
| `caller` | string | always | caller id, `unknown`, or `system` for a note |
| `tool` | string\|null | always | registry name only |
| `decision` | string | always | `allow`, `hidden`, `invalid`, `denied`, `refused`, `failed`, `blocked`, `dropped`, `note` |
| `reason` | string\|null | always | a `ReasonCode` value; `sink.failed` for a note |
| `transport` | string | always (new) | `stdio`, `http`, `cli` |
| `rules` | object | always | `tool`, `contract`, `classification` hashes |
| `dropped`, `truncated`, `flags`, `refusal_step`, `source`, `simulated`, `status_counts` | as today | optional | unchanged |
| `note` | object | note only | `{receipt, sink, exception}`; exception is a class name or `SinkTimeout` |

Never: an argument, a result, a field value, a token, a canary, a pseudonym key, a client IP, an exception message.

Structured line: the fields above plus `v` = 1 and `type` (`receipt`|`note`), every key present (null if absent).

## Sink

| Field | Type | Rule |
|---|---|---|
| name | string | config key |
| kind | `log` \| `database` | at most one `database` |
| mode | `required` \| `best_effort` | at least one `required` |
| format | `line` \| `structured` | log only; default `line` |
| channel | string | log only; default `context-gate.channel` |

Default when `receipts.sinks` is null: `{log: {kind: log, mode: required, format: line}}`.

## Settings

| Key | Default | Cap |
|---|---|---|
| `receipts.retention_days` | 30 | 180 (`Caps::RECEIPT_RETENTION_DAYS`) |
| `receipts.timeout_seconds` | 1.0 | 1.0 (`Caps::SINK_TIMEOUT`) |
| `receipts.connection` | null (`database.default`) | — |
| `receipts.table` | `gate_receipts` | — |
| query window | 24 h | 31 days (`Caps::AUDIT_WINDOW_DAYS`) |
| query limit | 100 | 1000 (`Caps::AUDIT_LIMIT`) |

## Table `gate_receipts`

See contracts/gate-receipts-table.md. One row per record (receipts and notes). `expires_at = at + retention`.

## Metric

`{name, filter}` from `src/Receipt/Metrics.php`; excludes preview (`source` set) and simulated receipts. List in
research R8.

## State transitions (one receipt)

```text
built → required sinks written ──all ok──→ best-effort sinks ──fail/slow──→ note on each required sink
                     │                                    └──ok──→ done
                     └──any fail──→ note on other required sinks (best effort) → ReceiptNotWritten → no response
stored row: live (expires_at > now) → expired (hidden from queries) → pruned (deleted)
```
