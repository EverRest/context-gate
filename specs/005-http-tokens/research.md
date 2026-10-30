# Research: HTTP Access and Tokens

## R1. Authentication and abilities use Sanctum

- **Decision**: Tokens are Sanctum personal access tokens (`laravel/sanctum ^4`). The HTTP route uses
  `auth:sanctum` and the ability middleware `abilities:gate:read` (Sanctum's ability check). Ceiling abilities
  are read from the same token.
- **Rationale**: D35 revised after the Lumen drop (D36). Sanctum already stores expiry and abilities; a second
  table for the same data would be a second source of truth.
- **Alternatives considered**: own `gate_tokens` table (rejected in D36; needed only for Lumen).

## R2. Expiry and revocation

- **Decision**: `gate:token` creates the token with `expires_at` set from `--expires`. Expiry is checked by Sanctum
  on every request against the server clock in UTC (FR-015). Revocation deletes the token row; the next request
  fails authentication (401), so revocation takes effect immediately (SC-004).
- **Verification point**: confirm in the Sanctum version used that an expired token fails the guard without a
  database write. Covered by a test.

## R3. Ceiling set excludes `secret`

- **Decision**: valid ceilings are `public`, `internal` and `personal`. `secret` is not offered by `gate:token`
  and is refused as an ability.
- **Rationale**: Fields classified `secret` never leave the server (spec 002, FR-005). A ceiling of `secret` would
  therefore grant nothing and would suggest that secret data is reachable with a higher token. Removing it keeps
  the model honest. This is a change to spec FR-004 and FR-007, recorded in the spec.
- **Alternatives considered**: keep `secret` as a ceiling with no effect (misleading); refuse `secret` only in
  the command (the ability could still be set by hand; rejected).

## R4. Token values

- **Decision**: Sanctum stores the token as a one-way hash in `personal_access_tokens` and returns the plain text
  only once, from `createToken()`. `gate:token` prints it once (FR-008) and never prints it again. `--list` reads
  metadata only (FR-010).
- **Verification point**: confirm in the Sanctum version used that the stored value is not the plain text. A test
  reads the row and asserts the plain token is absent.

## R5. Origin check is added by the package

- **Decision**: the package adds `GateOriginCheck` middleware to the HTTP route. It compares the `Origin` header
  with the configured allowlist. No Origin header is accepted (non-browser clients). A foreign Origin returns 403
  (FR-014).
- **Rationale**: Verified in `laravel_mcp/src/Server/Registrar.php`: the HTTP route has `ReorderJsonAccept`,
  `ValidateMcpHeaders` and `AddWwwAuthenticateHeader`, and no Origin check. `Mcp::web()` returns the route, so
  the package appends its middleware with `->middleware()`.
- **Alternatives considered**: relying on laravel/mcp (not available); a reverse proxy rule (outside the package,
  cannot be tested here).

## R6. Issuer and holder storage

- **Decision**: holder is the Sanctum token `name`. The issuer and the issuing time are stored in a small table
  `gate_token_issuers` (`token_id`, `issuer`, `created_at`), one row per token, written in the same transaction as
  the token. The migration ships with the package.
- **Rationale**: Sanctum has no free field for the issuer; the list command needs the issuer (FR-017).
- **Alternatives considered**: encoding the issuer in the token name (fragile, limits name length); reading the
  issuer from receipts (not practical for list).

## R7. Status codes

- **Decision**: no token or invalid token → 401 with `WWW-Authenticate` (laravel/mcp `AddWwwAuthenticateHeader`
  plus Sanctum). Missing `gate:read` ability → 401 (FR-003). No ceiling ability → 401 with receipt reason
  `token.no_ceiling` (FR-004). Foreign Origin → 403. Response bodies carry no reason beyond the status
  (FR-016).

## R8. No-ceiling detection

- **Decision**: `GateTokenAuth` runs after `auth:sanctum`. It counts `gate:ceiling:*` abilities on the token. Zero
  or more than one → 401 with `token.no_ceiling` (exactly one is required, per FR-004 and the ceiling rule).
  The ceiling is stored on the request for the spec 002 pipeline (FR-012).

## R9. Ceiling source

- **Decision**: the ceiling comes only from the token (`GateTokenAuth` sets it on the request). `clientInfo` in
  `initialize` is never read for the ceiling (FR-012, SC-005). For stdio (spec 002) the ceiling still comes from
  configuration.

## R10. Production

- **Decision**: stdio refusal in production stays (spec 001). HTTP is served in production for valid tokens
  (FR-013, D20).

## R11. Issuance receipts

- **Decision**: `gate:token` writes a receipt with `event: token.issued` (or `token.revoked`), `issuer`, `holder`,
  `ceiling`, `expires_at`, `token_id`. The token value is never included (FR-009, SC-003).
