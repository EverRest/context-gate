# Contract: Firewall pipeline

Applies to every tool response. Stage order is fixed (research R1). Each stage has a fail-closed
behaviour, listed in the last column.

| # | Stage | Input | Output | On failure |
|---|---|---|---|---|
| 1 | Fetch | tool arguments | raw rows (handler) | handler exception → `handler.failed` (spec 001) |
| 2 | Classify check | rows, catalog, caller ceiling | rows with allowed fields | unknown class → field dropped (`field.unclassified`) |
| 3 | Project | contract | ordered fields | missing contract → whole response refused (`policy.denied`) |
| 4 | Relations | related rows | nested objects | related failure → relation dropped |
| 5 | Transform | fields and forms | pseudonyms, buckets, dates | pseudonym key failure → refused (`pseudonym.keyfail`) |
| 6 | Normalize | text | NFKC, invisible characters removed | cannot run (missing `ext-intl`) → refused; `gate:check` fails |
| 7 | Redact | text fields | redacted text | exception → field dropped (`redaction.failed`) |
| 8 | Canary | serialized output | pass or block | match → blocked (`canary.hit`) |
| 9 | Quarantine | text fields | untrusted wrapper, flags | never blocks; flags only |
| 10 | Budget | serialized output | output or truncated output | counters exceeded → refused (`budget.*`, `rate.limited`) |
| 11 | Receipt | decision, reasons, rule hashes | receipt id | receipt not written → response not returned |

## Response shape (success)

```json
{
  "content": [{"type": "text", "text": "{\"status\":\"paid\",\"total_band\":\"100-1000\",\"customer\":\"USR_7f3a\"}"}],
  "isError": false,
  "structuredContent": {"rows": 1, "truncated": false, "flags": []}
}
```

Text fields from sources appear as:

```json
{"untrusted": true, "source": "docs:README.md#L10-40", "text": "..."}
```

## Config keys (added to context-gate.php)

```php
'firewall' => [
    'ceiling' => 'internal',                       // stdio only; tokens supply it in spec 005
    'limits' => [
        'per_call_bytes' => 16_000,                // cap 65536
        'per_session_bytes' => 400_000,            // cap 2_097_152
        'per_day_bytes' => 5_000_000,              // cap 20_971_520
        'calls_per_minute' => 60,                  // cap 300
    ],
    'canaries' => ['values' => [env('CONTEXT_GATE_CANARY_EMAIL'), env('CONTEXT_GATE_CANARY_TOKEN')]],
],
```

Any value above its cap makes `gate:check` exit with code 1 and name the key (FR-022, FR-025).

## Receipt (extended)

```json
{"v":1,"at":"2026-10-30T14:02:11Z","session":"s_4c1e","caller":"ivan","tool":"orders.status",
 "decision":"allow","reason":null,"rules":{"tool":"a41f09","contract":"7c2e1b","classification":"e90d55"},
 "dropped":[{"field":"notes","reason":"field.unclassified"}],"pseudonyms":2,"redacted":0,
 "truncated":false,"flags":[],"bytes":812}
```

No field value, no pseudonym key, no canary value appears in a receipt (FR-024).
