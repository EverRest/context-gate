# Contract: Cursor and Query Tool Response

## Cursor as the client sees it

`c_` followed by 32 lowercase hex characters (`random_bytes(16)`). It encodes no field value, record key, sort value
or offset (FR-016). The client sends it back unchanged, with the same `filters`, `sort` and `page_size`.

## Server-side state (never sent)

```json
{
  "binding": "<sha256 of caller, tool, rule hashes, ceiling, scope binding key, filters, sort, page_size>",
  "position": {"sort": "<last served sort value>", "key": 1234},
  "page": 3,
  "walk": "9f1c0a2b",
  "expires": 1793520000
}
```

Key `context-gate:cursor:<sha256(caller id)>:<cursor id>`, TTL = `query.cursor_ttl_seconds` (default 900, cap 3600).
Per-caller index `context-gate:cursors:<sha256(caller id)>`, at most 100 ids, same TTL, oldest retired first.

## Page response with more rows

```json
{
  "content": [
    {"type": "text", "text": "[{\"status\":\"confirmed\",\"athlete_id\":\"ATH_7f3a…\"}, …]"},
    {"type": "text", "text": "Notice: more rows are available; call again with the same arguments and \"cursor\": \"c_4be0…\"."}
  ],
  "isError": false,
  "structuredContent": {"rows": 50, "truncated": false, "flags": [], "cursor": "c_4be0…", "more": true}
}
```

## Last page

No `cursor`, no `more`, no "more rows" notice (FR-015). An empty result is `[]` with no cursor; it is not a refusal.

## Cursor could not be stored

Rows are returned; no cursor; `Notice: result incomplete; more rows may exist but no cursor could be issued.`

## Refusals (identical bytes within each row)

| Case | Text | Receipt reason |
|---|---|---|
| unknown, expired, foreign, tampered, mismatched cursor; store unreadable | `Invalid arguments for tool [<name>].` | `cursor.invalid` |
| malformed filter, bad bucket label, date finer than precision, >20 `in` values, no filter without opt-in | `Invalid arguments for tool [<name>].` or the schema message `Invalid arguments: …` (as today for schema errors) | `input.invalid` |
| filter or sort on a plain field above the caller's ceiling | `Request refused.` | `filter.denied` |
| unknown, expired or foreign pseudonym in a filter | `Request refused.` | `pseudonym.unknown` |
| budget, rate limit, canary | as today | as today; the presented cursor is not consumed |
