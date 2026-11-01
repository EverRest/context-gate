# Data Model: Contract Query Tool with Pagination

No tables. Cursor state lives in the gate cache store with a TTL.

## QueryDeclaration (`src/Query/QueryDeclaration.php`, from the tool entry's `query` array)

| Key | Type | Default | Rule |
|---|---|---|---|
| `filters` | map field => list of operators | — | each field listed by the contract; operators allowed for its form and class (research R4); no text, aggregate or relation field |
| `sort` | list of fields | `[]` | plain `public`/`internal` fields only (FR-008) |
| `page_size` | int | effective `query.page_size` | 1..min(`query.page_size`, `Caps::QUERY_PAGE_SIZE` 200) |
| `unfiltered` | bool | `false` | FR-026; listed by `gate:check` when true |

Any other key fails. `filters` empty with `unfiltered` false fails. Canonical form (key-sorted) goes into the `tool` rule
hash.

## Operators

| Operator | Arguments shape | Forms |
|---|---|---|
| `eq` | `{"eq": v}` | plain, pseudonym, date |
| `in` | `{"in": [v, …]}` 1..20 (plain) or 1..buckets (bucket) | plain, bucket |
| `range` | `{"from": v}`, `{"to": v}` or both, inclusive | plain, date |

The declaration names `range`; the call sends `from`/`to`.

## Filter (parsed, `src/Query/Filter.php`)

| Field | Type | Recorded in receipt |
|---|---|---|
| column | string (from the declaration, never from the caller) | yes |
| op | `eq` \| `in` \| `range` | yes |
| form | Field form | no |
| bound values | ints/strings/record id/period bounds, server-side only | **never** |

## QueryPlan (per call, bound as `context-gate.query`)

| Field | Type | Notes |
|---|---|---|
| tool | string | entry key |
| filters | list<Filter> | AND |
| sort | `{column, dir}` or null | null = key ascending |
| pageSize | int | effective, after clamping |
| unfiltered | bool | true when no filter was sent (only on an opted-in tool) |
| cursorIn | `CursorState` or null | the presented cursor's state |
| binding | string | sha256 canonical binding (research R7); server-side only |

## QueryPage (per call, filled by `QueryTool::handle`)

| Field | Type | Notes |
|---|---|---|
| positions | list<{sort, key}> | one per raw row served to the firewall, same order |
| more | bool | the probe row (`page_size + 1`) existed |
| page | int | 1 for a first call, else `cursorIn.page` |
| outcome | `{cursor: ?string, incomplete: bool}` | set by `FirewallStep` after the firewall |

## CursorState (cache value, key `context-gate:cursor:<sha256(caller)>:<id>`)

| Field | Type | Rule |
|---|---|---|
| binding | string (64 hex) | must equal the binding recomputed for the presenting call |
| position | `{sort: scalar\|null, key: int\|string}` | never leaves the server |
| page | int | ≥ 2 |
| walk | string (8 hex) | short hash of the walk's first cursor id; also written to receipts |
| expires | int (unix seconds) | issue time + effective TTL |

Cache TTL = effective `query.cursor_ttl_seconds` (default 900, cap `Caps::CURSOR_TTL` 3600).

## CursorIndex (cache value, key `context-gate:cursors:<sha256(caller)>`)

| Field | Type | Rule |
|---|---|---|
| ids | list<{id, issued}> | at most `Caps::CURSORS_PER_CALLER` (100); the oldest is retired and its state deleted when one more is issued |

TTL: the effective cursor TTL, refreshed on each issue.

## Cursor (as the client sees it)

`c_` + 32 lowercase hex. Pattern `^c_[0-9a-f]{32}$`. Encodes nothing (FR-016).

## Receipt additions (`src/Server/Receipt.php`)

| Field | Type | Notes |
|---|---|---|
| `query.filters` | list<{field, op}> | names and operators only |
| `query.sort` | `{field, dir}` or null | no sort value |
| `query.unfiltered` | bool | FR-023 |
| `query.cursor_in` | bool | a cursor was presented |
| `query.cursor_out` | bool | a cursor was issued |
| `query.page` | int | page number in the walk |
| `query.walk` | string (8 hex) or absent | links pages of one walk; not the cursor id |

## Reason codes (`src/Server/ReasonCode.php`)

| Code | Decision | Model sees |
|---|---|---|
| `cursor.invalid` | invalid | `Invalid arguments for tool [<name>].` |
| `filter.denied` | refused | `Request refused.` |

## Settings

| Key | Type | Strict | Balanced (cap) | Rule |
|---|---|---|---|---|
| `query.page_size` | int or null | 50 | 200 (`Caps::QUERY_PAGE_SIZE`) | above the cap fails `gate:check` |
| `query.cursor_ttl_seconds` | int or null | 900 | 3600 (`Caps::CURSOR_TTL`) | above the cap fails `gate:check` |

Code only: `Caps::FILTER_IN_VALUES` (20), `Caps::CURSORS_PER_CALLER` (100).
