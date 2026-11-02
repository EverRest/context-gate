# Tokens

HTTP callers authenticate with a bearer token issued by the gate. A token grants `gate:read`, exactly one ceiling
ability, `gate:ceiling:<class>`, which sets the most sensitive class that caller may receive, and one role ability,
`gate:role:<name>`, which names the role whose tools the caller may use.

## Issue a token

```bash
php artisan gate:token --holder="Example On-call" --issuer="Example Operator" --role=support --expires=30d
```

- `--holder`: the name of the caller the token is for. It is stored as the token name.
- `--issuer`: the person who issued it. Up to 120 characters.
- `--role`: required. A role defined in `roles` in `config/context-gate.php`; an undefined role fails with exit `1` and
  nothing is issued. The role is stored with the token and cannot be changed afterwards.
- `--ceiling`: optional. `public`, `internal` or `personal`; `secret` cannot be issued over HTTP. Without it the token
  takes the role's ceiling. With it, the value may only be equal to or narrower than the role's; a wider one fails with
  exit `1` and nothing is issued.
- `--expires`: a whole number of days, for example `30d`. The maximum is 90 days (`90d`). An expiry in the past,
  beyond 90 days, or missing, fails with exit `1` and nothing is issued.
- `--ability`: optional and repeatable. Only `gate:read` and the ceiling ability for the chosen ceiling are accepted;
  anything else fails.

**The token value is shown once.** Copy it into the caller's secret store when the command prints it. It cannot be
displayed again. Only a hash is kept. The `token.issued` receipt records the holder, issuer, ceiling, expiry and token
id, never the value.

## Caller key

A token's caller id is `token:<id>`. `gate:token` prints it on issuance and `gate:token --list` has a `caller` and a `role`
column. A token sees exactly the tools its role lists under `roles` in `config/context-gate.php`:

```php
'roles' => [
    'support' => ['tools' => ['customer_lookup'], 'ceiling' => 'internal'],
],
```

No config change is needed after issuance: a new token with a defined role works at once. Changing a token's role is not
possible; revoke it and issue a new one. A token issued before roles existed has no role and sees no tools.

A call's ceiling is the narrower of the token's ceiling and its role's, so a role never widens a token. Per-role limits
(`calls_per_minute` and the byte limits) apply to each caller of the role; counters stay per caller id.

`gate:check` warns, without failing, for each active token (issued, not revoked, not expired) whose role is missing or not
defined. The warning names the token id, the holder and the role, never the token value. A token whose role is not
defined sees no tools, and its calls answer as unknown tools with the reason `role.unknown` in the receipt.

## Migrating from callers

Earlier configs listed tools under a `callers` key. That key is removed and `gate:check` fails on it. To migrate:

1. For each group of callers that shared a tool list, define one role under `roles` with the same `tools` and a
   `ceiling` (the most sensitive class the callers may receive).
2. Stdio: move each OS user from `callers` to `stdio_roles`, mapped to that role.
3. HTTP: revoke each token that is listed under a `token:<id>` key and issue a new one with `--role=<name>`. A token
   issued before roles existed has no role and sees no tools until it is reissued.
4. Delete the `callers` key and run `gate:check`.

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
`expires_at`, `issued_at`, `caller` and `role`; under `--json` an error goes to stderr and stdout stays empty.

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
| `gate:role:<name>` | The tools of that role; at most one per token, set at issuance |

A token without `gate:read` or with an unknown ability is refused with `403` and
`WWW-Authenticate: Bearer error="insufficient_scope"`. A token with no ceiling ability, or with more than one, is refused
with `401` and `WWW-Authenticate: Bearer error="invalid_token"`. A token with more than one role ability is refused with `403`. The ceiling in the request body or the client name has no
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
requests that end in a `401` or `403` (including an origin refusal) in the gate cache store (`cache_store`) over a 60
second window, per client IP and presented credential (the key holds the IP and the first 16 hex characters of the
sha256 of at most 512 bytes of the bearer token, or `none`). Successful requests never count, so a client with a valid
token never accumulates refusals and other clients behind the same IP cannot lock it out. The throttle has two counters.

1. **Per credential (the only denial).** Once one (IP, credential) pair reaches `http.refusals_per_minute` (default 60,
   cap 600) the next request with the same credential from that IP gets `429` with `Retry-After: 60` and a generic
   JSON-RPC error body (`Too many refused requests.`), and one `http.throttled` receipt is written for that pair in
   that window (caller `unknown`; neither the IP nor the token hash is in it).
2. **Per IP, for log volume only.** A coarse counter per IP (threshold 10 times the limit, at most 6000) never denies
   service. Above it, anonymous refusals (caller `unknown`) are not recorded, except one `http.throttled` summary per
   IP per window; requests still get their normal `401` or `403`. A refusal attributed to a known token
   (`token:<id>`, for example `token.no_read` or `origin.denied`) is always recorded. The origin check looks the token
   up for attribution only when the bearer is well-formed (`<digits>|<40 or more alphanumeric characters>`, at most
   512 bytes); a malformed or missing bearer costs no query.

A valid token is never answered `429` because of other clients' refusals. The package does not deny service by IP and
does not stop token rotation: a flood that rotates garbage tokens is not limited here (each well-formed attempt costs
one indexed lookup, a malformed one costs nothing). Rate limiting at the reverse proxy or WAF is the right layer for
floods.

Allowed requests from clients that are not throttled are unaffected. See
[configuration.md](configuration.md#httprefusals_per_minute).

The client IP is whatever `$request->ip()` yields, so it follows Laravel's trusted-proxy configuration. Behind a reverse
proxy or load balancer the host MUST configure `trustProxies` with the proxy's address (not `*` from untrusted
networks); otherwise every client shares the proxy's address, so the coarse per-IP log suppression applies to every client together.

## Example request

```bash
curl -s -X POST https://app.example.test/mcp/context-gate \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"example","version":"1"}}}'
```

Responses: no `Authorization` header returns `401` with `WWW-Authenticate: Bearer`. An invalid, expired or revoked
token returns `401` with `WWW-Authenticate: Bearer error="invalid_token"`. A valid token that lacks `gate:read` or carries an
unknown ability returns `403` with `WWW-Authenticate: Bearer error="insufficient_scope"`. A valid token with no ceiling or
more than one returns `401` with `WWW-Authenticate: Bearer error="invalid_token"`. The
body is the same generic text in every case; the receipt records the reason code. A client past the refusal limit gets `429` with `Retry-After: 60`.
