# Research: Contract Query Tool with Pagination

Code read at HEAD `0e5d7f8`. Spec 018 (runbooks) already added a tool built from a config entry (`RunbookTool`,
`ToolRegistry::instantiate`); this plan reuses that pattern.

## R1. How a query tool is declared

**Decision**: a config tool entry whose `class` is `ContextGate\Query\QueryTool` and which carries a `query` array:

```php
'tools' => [
    'entries.list' => [
        'enabled' => true,
        'class' => \ContextGate\Query\QueryTool::class,
        'contract' => \App\Gate\EntryContract::class,
        'gate' => 'gate.entries',
        'fingerprint' => '…',                      // gate:fingerprint entries.list
        'query' => [
            'filters' => [
                'status' => ['eq', 'in'],
                'event_code' => ['eq'],
                'athlete_id' => ['eq'],             // pseudonym field
                'entered_at' => ['eq', 'range'],    // date field (month)
                'age' => ['in'],                    // bucket field
            ],
            'sort' => ['event_code', 'status'],
            'page_size' => 50,                      // optional; at most query.page_size, at most 200
            'unfiltered' => false,                  // FR-026; default false
        ],
    ],
],
```

`ToolRegistry::instantiate` builds `new QueryTool($key)` (as it does `new RunbookTool($key)`); the entry key is the tool
name. `QueryTool` reads its contract and declaration from the entry. No host class is written (FR-001, SC-001).

**Rationale**: plain arrays survive `config:cache`; objects in config do not. The entry already holds `contract`,
`gate`, `enabled` and `fingerprint`, so the generated tool is enabled, gated and pinned exactly like a hand-written
one (FR-003). Spec 013 adds a sibling `aggregate` key on its own tool class.

**Alternatives rejected**: a `query()` method on `ContextContract` (a contract would carry one declaration, but two
tools over one contract with different filters are a real need, e.g. support vs analyst); a separate `queries` config
section (a second visibility path next to `tools`, against constitution III's single allow list).

## R2. Declaration validation

**Decision**: `src/Query/QueryDeclaration.php` parses and validates the `query` array against the contract and the
catalog. Violations (all reported, each naming tool and field, FR-004):

- a filter or sort field not listed by the contract;
- an operator not allowed for the field's form and class (R4 table), or an unknown operator;
- a filter on a text, aggregate or relation field (FR-007);
- a sort field that is not a plain `public`/`internal` field (FR-008);
- `page_size` not an int in 1..`Caps::QUERY_PAGE_SIZE`;
- `unfiltered` not a bool; unknown keys; an empty `filters` map with `unfiltered` false (no call could ever pass);
- the contract itself invalid (existing `ContractGuard`).

Per call, a violation refuses with `config.invalid` (like `ContractGuard` today). In `gate:check`, a new
`src/Readiness/Checks/QueryCheck.php` fails and names each violation, and prints one info line per tool that opts in to
unfiltered listing (FR-026: "gate:check MUST list each query tool that opts in"). The parsed declaration is memoised
per (entry, classification map), like `ContractGuard`.

## R3. Generated name, description, schema and fingerprint

**Decision**:
- **Name**: the entry key (`ToolRegistry` already enforces `^[A-Za-z0-9_.-]{1,128}$`).
- **Description**: generated from the declaration only, never from data: "Lists rows of <contract short name>. Filters:
  status (eq, in), …. Sort: event_code, status. Pages of at most N rows; when more rows exist the response carries a
  cursor; call again with the same arguments plus the cursor." Plus "At least one filter is required." when
  `unfiltered` is false.
- **Annotations**: the `Tool` default (read-only, non-destructive, idempotent, closed-world), FR-002.
- **Input schema** (contracts/query-tool-input.schema.json): top-level `filters`, `sort`, `page_size`, `cursor`, with
  `additionalProperties: false`. `filters` is an object with one property per declared field; each field property is
  an object whose properties are only the declared operators (`eq`, `in`, `from`, `to`), each typed for the field's
  form (R4). `required: ["filters"]` when `unfiltered` is false. Only keywords `ArgumentValidator` evaluates are used
  (type, enum, pattern, maxItems, minItems, minimum, maximum, properties, required, additionalProperties), so the schema
  check is real, not advisory.
- **Fingerprint**: unchanged `Fingerprint::of($tool)` over name, description, schema, annotations. Since the schema is
  generated from the contract and the declaration, a new filterable field, a changed operator, page size or the
  unfiltered opt-in changes the fingerprint and hides the tool until re-pinned (US1 scenario 4). `gate:fingerprint`
  needs no change: it already prints `Fingerprint::of` of each instantiated tool.
- **Rule hash**: `ContextGateServiceProvider::ruleHashes` adds the canonical `query` declaration to the tool's entry in
  the `tool` hash (today only the description is hashed), so the hash changes with the declaration (FR-026, Key
  Entities) even where the description would not.

## R4. Filter operators per field form and class (FR-006 to FR-010)

| Field form (catalog class) | Operators | Value shape | Server-side SQL |
|---|---|---|---|
| plain (`public`, `internal`) | `eq`, `in` (1..20), `from`/`to` (range, inclusive, either or both) | string, integer, number or boolean (`eq`, `in`); string or number (range) | `=`, `IN`, `>=`, `<=` |
| pseudonym (`internal`, `personal`) | `eq` | string with the field's prefix, pattern `^<prefix>_[0-9a-f]{10,64}$` | resolved to the record id like `PseudonymArguments`; `= id` on the model key |
| bucket (any non-secret) | `in` (1..number of buckets) | enum of the field's labels as `Transformer::bucket` writes them (`<10`, `10-20`, `40+`) | each label → `[low, high)`; OR of ranges inside one parenthesised group, ANDed with the rest |
| date (`public`, `internal`; personal is refused by `ContractValidator`) | `eq`, `from`/`to` | `^\d{4}$` (year) or `^\d{4}-(0[1-9]\|1[0-2])$` (month), at exactly the field's precision | period → `[start, next start)` on the raw column |
| text, aggregate, relation | none (FR-007) | — | — |

Rules applied per call by `src/Query/QueryArguments.php`, after schema validation:
- exactly one operator family per field: `eq`, or `in`, or `from`/`to`; mixing is `input.invalid`;
- a plain field whose class is above the caller's effective ceiling: `filter.denied` (FR-009). Pseudonym, bucket and
  date fields never return the raw value and so follow `ClassifyCheck`'s NON_RAW rule: no ceiling refusal;
- a bucket label that is not a declared label, a date finer than the precision, more than 20 `in` values: `input.invalid`
  (the schema catches most; the parser checks again);
- an unknown, expired or foreign pseudonym: `pseudonym.unknown`, through the same resolution code as arguments today
  (`PseudonymArguments::record` becomes `public static resolveOne(...)`);
- filters are ANDed (FR-010). The bucket `in` OR is between bounds of one field only, never across fields.

**Sort** (FR-008): only declared plain `public`/`internal` fields at or below the caller's ceiling; above the ceiling is
`filter.denied`. Direction `asc` or `desc`. Default sort when none is sent: the model key, ascending (the tie-breaker
alone).

**Reusable unit for spec 013**: `src/Query/FilterRules.php` holds the table above as code (`operatorsFor(Field, Ceiling
$class): list<string>`, `schemaFor(string $column, Field $field, list<string> $ops): array`, `ceilingDenies(...)`), and
`src/Query/FilterCompiler.php` turns parsed filters into `where` clauses on a query builder. Spec 013 (FR-001, FR-017,
"reuses the filter rules, schema generation, fingerprinting and receipts of spec 012") calls both unchanged and adds its
group-by rules beside them. Neither class knows about pages or cursors.

## R5. Query construction without SQL from user input

**Decision**: `src/Query/QueryRunner.php` builds the query with the query builder on the model's connection and table,
as `Relations::records` does: `$model->getConnection()->table($model->getTable())`.

- column names come only from the parsed declaration (validated against the contract at load); a filter key in the
  arguments is matched against the declared field list with `array_key_exists` before any use, so no caller string ever
  becomes an identifier;
- every value is a bound parameter (`where`, `whereIn`, `whereBetween` style calls); no `raw` method is used;
- the operator is mapped from a closed `match` (`eq` → `=`, …); no caller string becomes an operator;
- soft-deleted rows are excluded as in `Relations::records`;
- `CallScope::apply` (spec 014) is called before any filter, sort or limit (spec 014 FR-012);
- selected columns: the contract's field columns, the model key, the scope columns and the sort column; rows are
  returned raw to the firewall exactly like a handler's rows (FR-005).

## R6. Order and keyset position

**Decision**: total order = requested sort column and direction, then the model key ascending as the hidden
tie-breaker (FR-013). A page is read with `limit(page_size + 1)`: the extra row says whether more exist and is never
served.

The next page continues after the last served row with a keyset predicate built from the server-side position (R7):
`(sort > v) OR (sort = v AND key > k)` (direction-aware). Nulls: the sort expression is `(<col> IS NULL), <col>` for
ascending (nulls last) and the predicate has a separate branch for a null position. This keeps "no row twice within one
walk" under inserts and deletes (edge case), which an offset would not.

The tie-breaker value and the sort value are kept only in the cursor state on the server. The key column is not added
to the response: if the contract does not list it, the projection drops it as today.

## R7. Cursor: opaque id with server-side state

**Decision** (FR-016 to FR-021): a cursor is `c_` + 32 lowercase hex characters from `random_bytes(16)`. It encodes
nothing. The state lives in the gate cache store (`GateCache::store()`, spec 008) under
`context-gate:cursor:<sha256(caller)>:<id>` with a TTL, and holds:

| Field | Purpose |
|---|---|
| `binding` | sha256 of the canonical binding: caller id, tool name, rule hashes (tool, contract, classification), effective ceiling, `CallScope::bindingKey()` or `none`, the canonical `filters`, `sort` and effective `page_size` |
| `position` | `{sort: <last served sort value>, key: <last served key>}` |
| `page` | page number of the next page (2 for the first cursor) |
| `walk` | the first 8 hex of sha256 of the first cursor id of the walk, for receipts |
| `expires` | issue time + TTL (checked in addition to the cache TTL) |

A per-caller index `context-gate:cursors:<sha256(caller)>` lists live ids with their issue times. Issuing the 101st
retires the oldest (FR-021); the index has the same TTL as the newest cursor, refreshed on each issue (every cursor
store has a TTL, research rule). Index updates run under the store lock when the store is a `LockProvider` (as `Budget`
does).

**Validation of a presented cursor**: pattern check, read, `expires` check, binding recomputed from the current call and
compared with `hash_equals`. Any failure, including an unreadable store, gives `cursor.invalid` with the response text
`Invalid arguments for tool [<name>].` (identical to `input.invalid`, FR-019). A cursor sent with different filters,
sort or page size fails the binding (US3 scenario 4). A ceiling, scope or rule change fails the binding (US4 scenario 3).

**TTL**: `query.cursor_ttl_seconds`, strict 900 (default), balanced 3600 = `Caps::CURSOR_TTL` (FR-018). Lowering it only
shortens a walk (narrows), unlike the budget TTL of spec 007 R4.

**Why not a signed or encrypted cursor**: an HMAC or encrypted token carries the position (a sort value or key) to the
client; FR-016 forbids that even in encrypted form, and server-side state is what lets FR-020 and FR-021 (consume,
retire oldest) work. The cache store already holds record keys for pseudonyms (spec assumption).

**Consumption** (FR-020): a presented cursor is deleted only after the page it stands for is served (after the receipt
is written). A budget, canary or failure refusal leaves it usable until expiry. The new cursor for the next page is
issued before the receipt so the receipt can say whether one was issued.

**Store unavailable on write**: the page is served without a cursor and with the notice "result incomplete; more rows may
exist but no cursor could be issued." No cursor is invented (edge case).

## R8. Pages, budgets, truncation and the cursor position

- `QueryTool::handle` runs the query and returns the raw rows (at most `page_size`) as JSON, like any handler; it keeps
  the raw rows' position values and the "more exist" flag in a per-call `QueryPage` bound as `context-gate.query`.
- The rows pass every firewall stage in order (FR-005): scope, classify, …, budget.
- After the firewall, `served = count($payload->rows)`. Stages before budget keep rows one-to-one; budget truncation
  and the work bound keep a prefix. So the last served raw row is `raw[served - 1]`, and the next cursor continues from
  it (FR-014 second sentence, US3 scenario 2).
- A cursor is issued when `served < count(raw)` or the probe row exists. If `ScopeFilter` removed any row (only
  possible when SQL and predicate disagree), or `served` is 0 while rows exist (one row above the per-call limit), no
  cursor is issued and the incomplete notice is shown: the position could not be trusted, so the walk stops instead of
  looping or skipping.
- Each page is one call for `Admission` (per-minute) and counts its bytes in `Budget` (FR-022). No continuation is free.
- Canary in a page: the existing `CanaryHit` path returns before cursor issuance (FR-024).
- Page size: requested `page_size` clamped to the effective size = min(declaration, `query.page_size`,
  `Caps::QUERY_PAGE_SIZE`) (FR-012, edge case "clamped"). The per-call row cap (work bound, 10 000) and the page size:
  the smaller wins.

## R9. Response shape

`ResponseBuilder::result` gains an optional `QueryOutcome` (cursor or null, incomplete flag). With a cursor:
- `structuredContent` gets `cursor: "c_…"` and `more: true`;
- a `Notice:` block: "more rows are available; call again with the same arguments and \"cursor\": \"c_…\"." The cursor
  is repeated in text because the model reads content only (comment in `ResponseBuilder::result`). It is opaque, so the
  rule "text never carries a value" holds.
- The existing "result truncated to the per-call limit; narrow the request." notice is replaced by the "more rows" notice
  for a query tool, since the cursor continues the page.
- Last page: no cursor, no notice (FR-015). Empty result: `[]`, no cursor, not a refusal.
- No total count (Out of Scope).

## R10. Receipts

`Receipt::write` gains an optional `?array $query`, written as:

```json
"query": {"filters": [{"field": "status", "op": "in"}, {"field": "entered_at", "op": "range"}],
          "sort": {"field": "event_code", "dir": "asc"}, "unfiltered": false,
          "cursor_in": true, "cursor_out": true, "page": 3, "walk": "9f1c0a2b"}
```

No filter value, no cursor id, no sort value (FR-023). `walk` is the short hash FR-023 allows. The field is written for
every receipt of a query tool call after `QueryStep` (including `filter.denied`, `cursor.invalid` and budget refusals),
through `ResponseBuilder::receipt` reading the bound `QueryPage`, like the spec 014 scope summary.

## R11. Reason codes

- `CursorInvalid = 'cursor.invalid'`, decision `invalid`, response `Invalid arguments for tool [<name>].`
  (`ResponseBuilder::forReason` maps it like `InputInvalid`).
- `FilterDenied = 'filter.denied'`, decision `refused`, generic `Request refused.`
Both added to `docs/privacy.md`. Spec 013 reuses `filter.denied`.

## R12. Call path

`PolicyStep` → `Admission` → `ArgumentsStep` (canary on all argument values incl. filters, FR-011; schema; policy;
ceiling) → `ScopeStep` (spec 014) → **`QueryStep`** (only for `QueryTool`: declaration valid, parse filters and sort,
`filter.denied`, pseudonym filters, unfiltered rule, cursor validation; binds `QueryPlan`) → `FetchStep` (runs
`QueryTool::handle`) → `FirewallStep` (firewall; on success issue next cursor, write receipt, consume presented cursor,
build response).

`PseudonymArguments::resolve` already ignores the query tool's top-level arguments (they are not contract columns).

## R13. Dependency on spec 014 and 010

- Cursors bind `CallScope::bindingKey()` and the query applies `CallScope::apply`, both from spec 014. Plan order:
  010 → 014 → 012 → 013. If 012 must be built first, `QueryStep` binds `'none'` and the docs forbid enabling a query
  tool on a contract that needs a row scope (spec assumption); the first task after 014 lands switches the binding.
- Spec 010 changes the effective ceiling (narrower of role and token); `QueryStep` reads `Caller::ceiling()` as
  `ArgumentsStep` does, so it follows 010 without change.
