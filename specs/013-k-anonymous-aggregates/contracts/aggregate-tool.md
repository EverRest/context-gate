# Contract: Generated Aggregate Tool

The tool is built from a tool entry with an `aggregate` declaration (data-model.md). Everything below is generated from
the declaration and the contract and is covered by the tool fingerprint.

## tools/list entry

```json
{
  "name": "entries.count",
  "description": "Counts rows of EntryContract grouped by up to 2 of: nation, age (bucket), birth_date (year). Groups smaller than 10 are not shown; small groups are merged into \"other\" only when that sum is at least 10. Counts only rows you may see. No row values are returned.",
  "inputSchema": {
    "type": "object",
    "properties": {
      "filters": { "$comment": "spec 012 filter schema for the declared filter fields and operators" },
      "group_by": {
        "type": "array",
        "items": { "type": "string", "enum": ["nation", "age", "birth_date"] },
        "maxItems": 2,
        "uniqueItems": true
      },
      "percent": { "type": "boolean" }
    },
    "additionalProperties": false
  },
  "annotations": { "readOnlyHint": true, "destructiveHint": false, "idempotentHint": true, "openWorldHint": false }
}
```

`maxItems` = effective `max_group_by`. The description states k and the keys, so a change of either changes the
fingerprint.

## tools/call result (success)

`content[0].text` is the JSON below; `content[1]` is a `Notice:` block when anything is suppressed, withheld or cut.

```json
{
  "groups": [
    { "nation": "FRA", "count": 12, "percent": 48 },
    { "nation": "GER", "count": 7, "percent": 28 }
  ],
  "other": { "count": 6, "percent": 24 },
  "total": 25
}
```

| Case | `groups` | `other` | `total` | Notice |
|---|---|---|---|---|
| 12, 7, 3, 3, k = 5 | 12, 7 | 6 | 25 | some groups are below the minimum group size and are not shown. |
| 12, 7, 2, k = 5 | 12, 7 | absent | absent | + the total is withheld because it would reveal a suppressed group. |
| no suppression | all | absent | sum | none |
| no group-by, count 3, k = 5 | `[]` | absent | absent | the result is below the minimum group size. |
| no group-by, count 0 | `[]` | absent | 0 | none |
| > max_groups named | top max_groups | pool (if ≥ k) | per rule | + the number of groups exceeds the cap; smaller groups are merged into other. |

- Group order: count descending, then label tuple ascending (canonical JSON). Byte-identical on rerun.
- A missing value labels its group `null`.
- `percent` keys appear only when `percent: true` was sent and `total` is returned.
- Labels: `value` = stored value; `bucket` = `<10`, `10-19`, `20+`; `year` = `2008`; `month` = `2008-03`.

`structuredContent`: `{"groups": <int named>, "withheld": <bool>, "truncated": <bool>, "flags": []}`.

## tools/call refusals (generic texts, existing ResponseBuilder)

| Cause | Text | Receipt reason |
|---|---|---|
| unknown key in `group_by`, too many keys, malformed filter | `Invalid arguments: …` (schema) or `Invalid arguments for tool [entries.count].` | `input.invalid` |
| `value` key or filter field above the caller's ceiling | `Request refused.` | `filter.denied` |
| daily aggregate limit | `Request refused.` | `aggregate.budget` |
| scope not resolved | `Request refused.` | `scope.unresolved` |
| canary in a label | `Request failed. Reference: r_…` | `canary.hit` |
| work bound exceeded | `Request failed. Reference: r_…` | `transform.failed` |

## Receipt (success)

```json
{"id":"r_…","at":"…","caller":"token:12","tool":"entries.count","decision":"allow","reason":null,
 "rules":{"tool":"…","contract":"…","classification":"…"},
 "aggregate":{"group_by":["nation"],"filters":[{"field":"event_id","op":"eq"}],"k":10,"groups":2,"suppressed":2,"withheld":false}}
```
