# Tokens

HTTP callers authenticate with a bearer token issued by the gate. A token grants `gate:read` and exactly one ceiling
ability, `gate:ceiling:<class>`, which sets the most sensitive class that caller may receive.

## Issue a token

```bash
php artisan gate:token --holder="Example On-call" --issuer="Example Operator" --ceiling=internal --expires=30d
```

- `--holder`: the name of the caller the token is for. It is stored as the token name.
- `--issuer`: the person who issued it. Up to 120 characters.
- `--ceiling`: `public`, `internal` or `personal`. `secret` cannot be issued over HTTP.
- `--expires`: a whole number of days, for example `30d`. The maximum is 90 days (`90d`). An expiry in the past,
  beyond 90 days, or missing, fails with exit `1` and nothing is issued.
- `--ability`: optional and repeatable. Only `gate:read` and the ceiling ability for the chosen ceiling are accepted;
  anything else fails.

**The token value is shown once.** Copy it into the caller's secret store when the command prints it. It cannot be
displayed again. Only a hash is kept. The `token.issued` receipt records the holder, issuer, ceiling, expiry and token
id, never the value.

## Caller key

A token's caller id is `token:<id>`. `gate:token` prints it on issuance and `gate:token --list` has a `caller` column.
A token sees only the tools listed for its caller key under `callers` in `config/context-gate.php`:

```php
'callers' => [
    'token:12' => ['tools' => ['customer_lookup']],
],
```

A caller key that is not listed sees no tools. Budgets and rate limits are keyed by this id.

## Holder

The token holder is `ContextGate\Token\GateTokenHolder`, a model owned by the package. It is not a user of your
application, and the holder resolves without a morph alias from your application, so host user models are never
touched. A holder named in `--holder` is created on first use.

## List tokens

```bash
php artisan gate:token --list
```

Lists active tokens with their id, holder, issuer, ceiling, expiry and issue time. The list never shows token values.
Expired tokens are not listed. With `--json` it prints an array of objects with `id`, `holder`, `issuer`, `ceiling`,
`expires_at`, `issued_at` and `caller`; under `--json` an error goes to stderr and stdout stays empty.

## Revoke a token

```bash
php artisan gate:token --revoke=<id>
```

Revocation takes effect at once: the next request with that token gets `401`. The issuer record survives the
revocation. Revoking an unknown or already revoked id changes nothing and exits `1`.

## Ceiling abilities

| Ability | Grants |
|---|---|
| `gate:read` | Use the MCP endpoint at all |
| `gate:ceiling:public` | Public fields only |
| `gate:ceiling:internal` | Public and internal fields |
| `gate:ceiling:personal` | Public, internal and personal fields |

A token with no ceiling ability, with more than one, with an unknown ability, or without `gate:read` is refused with
`403` and `WWW-Authenticate: Bearer error="insufficient_scope"`. The ceiling in the request body or the client name has no
effect: the tool list and the results follow the token only.

## The origin rule

Browsers send an `Origin` header. The gate applies this rule before token checks:

- No `Origin` header: a non-browser client; the request continues to the token check.
- `Origin` exactly equal to an entry in `http.origins`: continues to the token check.
- Anything else, including an origin not in the list, the literal `null`, an empty value, or two `Origin` headers:
  refused with `403`, even when a valid token is sent.

Keep `http.origins` empty unless a browser client must reach the endpoint. See
[configuration.md](configuration.md#httporigins).

## Refusal throttle

The throttle runs first on the HTTP route, before the origin check, the token lookup and any database work. It counts
the requests of one client IP that the gate refuses with `401` or `403` (including an origin refusal), in the gate
cache store (`cache_store`) over a 60 second window. Successful requests never count. Once a client reaches
`http.refusals_per_minute` (default 60, cap 600) it gets `429` with `Retry-After: 60` and a generic JSON-RPC error
body, and one `http.throttled` receipt is written for that client in that window (caller `unknown`, no IP in it). A
client past half of the limit also skips the origin check's token lookup. See
[configuration.md](configuration.md#httprefusals_per_minute).

Behind a proxy or load balancer the IP is correct only when the host configures Laravel's trusted proxies; otherwise
every client shares the proxy's address and one flooding client can throttle all of them.

## Example request

```bash
curl -s -X POST https://app.example.test/mcp/context-gate \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"example","version":"1"}}}'
```

Responses: no `Authorization` header, or an invalid, expired or revoked token, returns `401` with
`WWW-Authenticate: Bearer`. A valid token that lacks `gate:read`, carries an unknown ability, or has no ceiling or more
than one returns `403` with `WWW-Authenticate: Bearer error="insufficient_scope"`. The body is the same generic text in
both cases; the receipt records the reason code. A client past the refusal limit gets `429` with `Retry-After: 60`.
