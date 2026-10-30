# Data Model: Laravel Sources

No new tables. Responses are built per request and returned through the firewall.

## RouteEntry

| Field | Type | Source |
|---|---|---|
| method | string | Router |
| uri | string, with `<SECRET>` substitutions | Router, R2 |
| name | string or null | Router |
| controller | string or null (`Class@method`) | Router action |
| rule_names | list of strings (keys of `rules()`) | FormRequest, R2 |
| middleware | list of names | Router (parameters redacted) |

Excluded before output: any URI matching the default or configured exclusion patterns (FR-002).

## ModelSchema

| Field | Type | Rule |
|---|---|---|
| model | class name | config list |
| table | string | model |
| columns | list of `{name, type, class}` | catalog class (spec 002); `secret` removed |
| hidden_columns | int | count of removed `secret` columns (FR-005) |
| indexes | list of `{name, columns, unique}` | Schema inspector |
| casts | map column → cast type | `getCasts()` |
| relations | list of `{name, model, type}` | reflection, R3 |

Error: `schema.missing` when the table is unreachable.

## ConfigKey

| Field | Type | Rule |
|---|---|---|
| key | dotted string | config walk |
| type | `string`, `int`, `float`, `bool`, `array`, `null` | leaf type only |
| env | variable name or `env:<hidden>` or null | `env_map` + SensitiveNames (FR-008) |

Values are never a field of this entity (FR-007).

## DocumentRead

| Field | Type | Rule |
|---|---|---|
| source | relative path as requested | allowlist root + relative name |
| text | string | after spec 002 firewall; wrapped `{untrusted: true, source, text}` |
| truncated | bool | per-call limit (spec 002) |

## Policy refusal (receipt only)

| Field | Type | Values |
|---|---|---|
| step | enum | `realpath`, `root`, `denylist`, `allowlist`, `extension` |

The receipt stores `step` and the reason code; it never stores the path (FR-017).
