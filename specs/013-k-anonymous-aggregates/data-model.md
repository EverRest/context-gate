# Data Model: k-Anonymous Aggregates

No tables. One cache counter per caller per UTC day. Everything else is computed per call.

## Code caps (`src/Pipeline/Caps.php`)

| Constant | Value | Meaning |
|---|---|---|
| `AGG_K_FLOOR` | 5 | lowest k; also the default |
| `AGG_K_CAP` | 1000 | highest k |
| `AGG_GROUP_BY` | 2 | most group-by keys in one call (FR-004) |
| `AGG_GROUPS` | 100 | most named groups in one response |
| `AGG_PER_DAY` | 500 | most aggregate calls per caller per UTC day (default 100) |
| `AGG_SCAN_CELLS` | 10000 | work bound on folded cells (research R4) |

## Setting keys (`config/context-gate.php`, validated by `SettingsSchema` / `SettingsValidator`)

| Key | Type | Default | Rule |
|---|---|---|---|
| `aggregates.k` | int or null | null (= 5) | `AGG_K_FLOOR` ≤ k ≤ `AGG_K_CAP`; may only raise |
| `aggregates.max_group_by` | int or null | null (= 2) | 1 ≤ v ≤ `AGG_GROUP_BY` |
| `aggregates.max_groups` | int or null | null (= 100) | 1 ≤ v ≤ `AGG_GROUPS` |
| `aggregates.queries_per_day` | int or null | null (= 100) | 1 ≤ v ≤ `AGG_PER_DAY` |

Not part of `Profiles` (research R2).

## AggregateDeclaration (tool entry key `aggregate`)

```php
'tools' => [
    'entries.count' => [
        'enabled' => true,
        'gate' => 'read-entries',
        'fingerprint' => '…',            // gate:fingerprint entries.count
        'contract' => EntryContract::class,
        'aggregate' => [
            'group_by' => ['nation' => 'value', 'age' => 'bucket', 'birth_date' => 'year'],
            'filters' => ['event_id' => ['eq', 'in'], 'status' => ['eq']],   // spec 012 shape
            'k' => 10,                   // optional, 5..1000, may only raise
        ],
    ],
],
```

| Field | Type | Rule |
|---|---|---|
| `group_by` | map column → `value`\|`bucket`\|`year`\|`month` | research R1; at least 1 key |
| `filters` | map column → list of operators | spec 012 FR-006 to FR-011 via the 012 filter unit |
| `k` | int, optional | 5 ≤ k ≤ 1000 |

An entry with `aggregate` has no `class`; one with both is `config.invalid`.

## Effective k

`k = max(aggregates.k ?? 5, declaration.k ?? 5)`, always within `[5, 1000]`.

## Call arguments (see `contracts/aggregate-tool.md`)

`filters` (012 shape), `group_by` (list of declared keys, 0..max_group_by, unique), `percent` (bool, default false).

## Cell and result

| Name | Shape | Notes |
|---|---|---|
| Cell | label tuple (one label per group-by key, `null` = missing) → count ≥ 1 | internal only |
| Named group | `{<key>: label, …, "count": n[, "percent": p]}` | only when n ≥ k |
| Other | `{"count": n[, "percent": p]}` | only when the pool sum n ≥ k |
| Total | int | only when it equals named + other |
| `withheld` | bool | total (and percentages) not returned |
| `cut` | bool | more than `max_groups` named groups |

## AggregateBudget counter (cache store `GateCache::store()`)

| Key | Value | TTL |
|---|---|---|
| `aggregate:day:{caller}:{Y-m-d}` | int, calls admitted today | `Budget::secondsUntilUtcMidnight(now)` |

## Reason codes (`src/Server/ReasonCode.php`)

| Value | Decision | Where |
|---|---|---|
| `aggregate.budget` | refused | over the daily aggregate limit |
| `aggregate.suppressed` | dropped | a `Field::aggregate()` count in 1..k-1 (dropped entry) |
| `filter.denied` | refused | from spec 012; group-by `value` key above the ceiling too |

## Receipt field `aggregate` (optional, aggregate tools only)

| Field | Type |
|---|---|
| `group_by` | list of key names used in the call |
| `filters` | list of `{field, op}` (spec 012 shape) |
| `k` | int |
| `groups` | int, named groups returned |
| `suppressed` | int, cells not named (pool + cut) |
| `withheld` | bool |

Never: a label, a count, a filter value.
