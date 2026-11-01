# Data Model: OpenAPI Source and Developer Tooling

No tables. Per-request structures and generated files.

## OpenApiDocument (in memory)

| Field | Type | Rule |
|---|---|---|
| version | string | must start with `3.` (R1) |
| source | local path or allowlisted host + path | origin for provenance |
| size | int bytes | ≤ 2 MB (FR-010) |

## OpenApiEndpoint

| Field | Type | Source |
|---|---|---|
| method | string | path item |
| path | string | key |
| parameters | list `{name, in, required, schema}` | parameters |
| request_schema | schema or null | requestBody |
| examples | map of Media Type examples, or null | requestBody content `examples`; null in `drop` mode or when none (FR-003) |
| response_schemas | map status → schema | responses |
| security | list of scheme names | security (names only, no secrets) |

## FetchRequest

| Field | Type | Rule |
|---|---|---|
| url | string | https, allowlisted host, no userinfo (R2 step 1) |
| resolved | list of addresses | R2 step 3 |
| pinned | address | first accepted (R2 step 5) |
| timeout | 5 seconds | fixed (FR-009) |
| max_bytes | 2 MB | fixed (FR-010) |

Refusal codes: `egress.denied` (host, address range), `source.redirect`, `source.timeout`, `source.too_large`,
`openapi.version`.

## CatalogDraftLine

| Field | Type | Values |
|---|---|---|
| table, column | strings | schema |
| class | enum | `personal`, `secret`, `internal`, `public` |
| marker | enum | `// suggested`, `// needs decision` |

## Preview

| Field | Type | Rule |
|---|---|---|
| tool | string | registry name |
| output | string | exact serialized response |
| dropped | list of `{field, reason}` | reason codes from spec 002 |
| receipt_id | string | `r_` + 8 hex |

## CanaryValue

| Field | Type | Shape |
|---|---|---|
| email | string | `canary-<8 hex>@example.invalid` |
| token | string | `CANARY_<24 hex>` |
| placements | list of strings | docs, OpenAPI example, config key |
