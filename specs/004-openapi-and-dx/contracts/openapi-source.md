# Contract: OpenAPI source and developer commands

## Tool: openapi.endpoints

Arguments: `{"path": "api/openapi.json"}` or `{"url": "https://docs.example.com/openapi.json"}` (exactly one).

Result: `{"version": "3.1.0", "endpoints": [OpenApiEndpoint, ...]}`.

Refusals (reason code and receipt, message `Request refused.`): `egress.denied`, `source.redirect`,
`source.timeout`, `source.too_large`, `openapi.version`. No host, address or document content in the response.

Firewall: the result passes the spec 002 pipeline; examples are already removed (default) or passed through the
redactor (`examples: redacted`).

## Command: gate:scan

```text
php artisan gate:scan [--json]
```

Output: a list of routes and models with a status (`ok`, `schema.missing`) and counts. No file is changed except
`storage/context-gate/scan.json` when `--json` is given. Exit 0.

## Command: gate:classify

```text
php artisan gate:classify [--force]
```

Writes `app/Gate/classification.php` as a draft. Refuses to overwrite without `--force` (exit 1 with message).
Draft lines follow CatalogDraftLine.

## Command: gate:preview

```text
php artisan gate:preview {tool} [--args=<json>]
```

Prints the serialized output, then `Dropped:` with one line per field (`field: reason`). No client receives it.
Writes a receipt with `source: preview`.

## Command: gate:canary

```text
php artisan gate:canary
```

Prints two values and placement suggestions. Writes nothing. Exit 0.

## Receipt additions

- `source`: `preview` for preview runs; tool name otherwise.
- `fetch`: `{host, resolved_count, pinned: true}` for URL requests, no addresses beyond the count.
