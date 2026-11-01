# Quickstart: Contract Query Tool with Pagination

Prerequisite: spec 014 merged and the contract declares its `scope()` (research R13). Do not enable a query tool on
data that needs a row scope before that.

## 1. Declare the tool

```php
// config/context-gate.php
'tools' => [
    'entries.list' => [
        'enabled' => true,
        'class' => \ContextGate\Query\QueryTool::class,
        'contract' => \App\Gate\EntryContract::class,
        'gate' => 'gate.entries',
        'query' => [
            'filters' => ['status' => ['eq', 'in'], 'athlete_id' => ['eq'], 'entered_at' => ['eq', 'range']],
            'sort' => ['status'],
            'page_size' => 50,
        ],
    ],
],
```

```bash
php artisan gate:check                       # fails and names any bad field or operator
php artisan gate:fingerprint entries.list    # copy the value into 'fingerprint'
```

Allow `entries.list` for a caller (role, spec 010).

## 2. Call it

```json
{"name": "entries.list", "arguments": {"filters": {"status": {"eq": "confirmed"}}, "sort": {"field": "status"}}}
```

Expected: at most 50 rows; when more exist, `structuredContent.cursor` and a `Notice:` naming the cursor.

```json
{"name": "entries.list", "arguments": {"filters": {"status": {"eq": "confirmed"}}, "sort": {"field": "status"}, "cursor": "c_…"}}
```

Expected: the next rows, none repeated; the last page has no cursor.

## 3. Check the refusals

| Arguments | Expected |
|---|---|
| `{}` or `{"filters": {}}` | `Invalid arguments for tool [entries.list].`, receipt `input.invalid` |
| a cursor with `status` changed | same text, receipt `cursor.invalid` |
| the cursor 16 minutes later | same text, receipt `cursor.invalid` |
| `{"filters": {"athlete_id": {"eq": "ATH_000…"}}}` (never issued) | `Request refused.`, receipt `pseudonym.unknown` |

## 4. Read the receipts

Each page's receipt has `query.filters` (names and operators), `query.page`, `query.cursor_in`, `query.cursor_out`,
`query.walk`; no filter value and no cursor id.
