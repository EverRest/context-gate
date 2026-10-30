# Quickstart: HTTP Access and Tokens

Requires specs 001–004.

## 1. Issue a token (User Story 3)

```bash
php artisan gate:token --holder="Ivan (on-call)" --issuer="Pavlo" --ceiling=internal --expires=30d
```

Expected: a token shown once; `gate_token_issuers` row; a `token.issued` receipt with no token value.

Run without `--expires`: exit 1, nothing issued. Run with `--expires=120d`: exit 1 naming the 90-day maximum.

## 2. Connect over HTTP (User Story 1)

```bash
curl -s -X POST http://localhost/mcp/context-gate \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"qs","version":"1"}}}'
```

Expected: an initialize result. Repeat with `clientInfo.name` claiming a higher ceiling: the tool list is unchanged.

## 3. Refusals (User Story 2)

- No `Authorization` header → 401 with `WWW-Authenticate`.
- Revoked token (step 4) → 401.
- Token without any `gate:ceiling:*` ability (create one with `--ability=gate:read` only through a test fixture) → 401; receipt `token.no_ceiling`.
- `Origin: https://evil.example` with a valid token → 403.

## 4. List and revoke (User Story 4)

```bash
php artisan gate:token --list
php artisan gate:token --revoke=<id>
```

Expected: list shows metadata only; after revoke, step 2 returns 401.

## 5. Production (User Story 1, scenario 4)

With `APP_ENV=production`: stdio refused (spec 001); HTTP with a valid token served.

## 6. Gates

```bash
composer test && composer stan && composer style
```

Expected: exit code 0 for each.
