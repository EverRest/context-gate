# Contract: receipt commands

## gate:audit --receipts

```text
php artisan gate:audit --receipts [--caller=<id>] [--tool=<name>] [--reason=<code>] [--decision=<d>]
                       [--since=<time>] [--until=<time>] [--limit=<n>] [--json]
```

| Case | Exit | Output |
|---|---|---|
| matches | 0 | text table `id  at  caller  tool  decision  reason`, newest first; or `--json`: a JSON array of records |
| no match | 0 | `No receipts.` or `[]` |
| database sink not configured | 1 | `queries need the database sink (receipts.sinks.<name>.kind = database)` on stderr |
| window > 31 days | 1 | `the window is above the cap of 31 days` on stderr |
| limit > 1000 | 1 | `the limit is above the cap of 1000` on stderr |
| bad time value | 1 | `since/until must be a time` on stderr |

Defaults: `--since=-24 hours`, `--until=now`, `--limit=100`. Expired rows are never printed. Records hold metadata
only. Each run writes one line on the receipt channel:
`{"event":"receipts.query","actor":"<os user>","at":"…","filters":["caller","since"]}`.

## gate:audit --summary

```text
php artisan gate:audit --summary [--since=<time>] [--until=<time>] [--json]
```

Prints `<metric> <count>` per metric of research R8 (zeros included), or a JSON object
`{"window":{"since":…,"until":…},"metrics":{"canary_hits":0,…}}`. Same caps, same audit line. No caller-level
breakdown.

## gate:audit (unchanged)

With neither flag: the tool listing of spec 006.

## gate:receipts:prune

```text
php artisan gate:receipts:prune
```

Deletes rows with `expires_at <= now()`. Prints `Pruned <n> receipts.` Exit 0; exit 1 with
`the database sink is not configured` when there is no database sink. Never prints a row field.

## gate:check lines added

```text
FAIL context-gate.receipts.sinks: no sink is required; at least one sink must be required
FAIL context-gate.receipts.retention_days: 365 is above the cap of 180
FAIL gate_receipts: the database sink is enabled and the table does not exist
WARN gate_receipts: 42 expired receipts are not pruned; schedule gate:receipts:prune
WARN context-gate.receipts.sinks.log: channel [context-gate] discards lines (null driver)
```
