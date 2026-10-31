# Contract: HTTP transport and token command

## HTTP route

`POST /mcp` (path from config; default `mcp/context-gate`). Registered with `Mcp::web()`, which returns the
route. The package appends middleware in this order:

1. laravel/mcp: `ReorderJsonAccept`, `ValidateMcpHeaders`, `AddWwwAuthenticateHeader` (already on the route).
2. Package: `auth:context-gate`, `GateOriginCheck`, `GateTokenAuth`, `abilities:gate:read`.

Responses:

| Case | Status | Body |
|---|---|---|
| No token | 401 | JSON-RPC error `Unauthenticated.`, `WWW-Authenticate: Bearer` |
| Expired, revoked or unknown token | 401 | same |
| Token without `gate:read` | 401 | same |
| No ceiling or several (`token.no_ceiling`) | 401 | same |
| Origin not allowlisted | 403 | empty body |
| Valid token and Origin (or no Origin) | 200 | MCP response |

Bodies never name the failed check (FR-016). Receipts record the reason code.

## Command: gate:token

```text
php artisan gate:token --holder=<name> --issuer=<name> --ceiling=<public|internal|personal> --expires=<Nd>
  [--ability=<gate:read|gate:ceiling:...>]   # repeatable, package set only
php artisan gate:token --list
php artisan gate:token --revoke=<token_id>
```

Rules:

- `--expires` is required; value in days, at most `90d` (FR-006). Missing or larger → exit 1, nothing issued.
- `--ceiling` is required and must be `public`, `internal` or `personal` (R3).
- `--ability` values outside the package set → exit 1 (FR-007).
- The token value is printed once on issuance (FR-008). The output says so.
- `--list` prints id, holder, issuer, ceiling, expires_at, issued_at (FR-010). No token value.
- `--revoke` deletes the token and the issuer row, writes `token.revoked` (FR-011). Unknown id → exit 1, nothing changes.

## Config

```php
'http' => [
    'path' => 'mcp/context-gate',
    'origins' => [],                       // exact origins, e.g. 'https://internal-tools.example.com'
],
```
