# Feature Specification: HTTP Access and Tokens

**Feature Branch**: `005-http-tokens`

**Created**: 2026-10-30

**Status**: Draft

**Input**: Owner brainstorm (roadmap milestone M5a): on-call and support need to reach the same tools remotely,
over HTTP, with a personal token. Tokens are issued only through one command, have a mandatory expiry of at
most 90 days, and carry a sensitivity ceiling that decides which classes of data the holder may see. Every
issuance and revocation is recorded.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Connect remotely with a personal token (Priority: P1)

An on-call engineer connects their assistant to the application over HTTP using a token issued to them. The
assistant lists and calls the tools that the token's ceiling allows. The same tools are available as over stdio,
and the responses pass the same firewall.

**Why this priority**: Remote access is the reason on-call and support need the package at all.

**Independent Test**: Issue a token for one person; connect over HTTP with it; list tools; call one; confirm the
receipt names the token holder.

**Acceptance Scenarios**:

1. **Given** a valid token with `gate:read` and a ceiling, **When** the client sends the initialize request
   over HTTP, **Then** the server answers with its name, version and capabilities.
2. **Given** the same token, **When** the client lists tools, **Then** only tools allowed for the token appear.
3. **Given** a tool call over HTTP, **When** the call completes, **Then** the receipt records the token holder
   as caller and the tool name, and no value.
4. **Given** the server runs with the production environment, **When** a client uses a valid personal token over
   HTTP, **Then** the request is served.

---

### User Story 2 - Only authorized tokens get in (Priority: P1)

Requests without a token, with an expired token, with a revoked token are refused with 401. A token that lacks
`gate:read` is refused with 403. A token without any sensitivity ceiling is refused as well, because a token without a ceiling
has no defined level of data access.

**Why this priority**: The HTTP surface is reachable by anyone who can reach the network; authentication must
be exact.

**Independent Test**: Try each refused case and confirm a 401 with no tool list and no internal detail.

**Acceptance Scenarios**:

1. **Given** no token, **When** a request is sent, **Then** the response is 401 with a `WWW-Authenticate` header.
2. **Given** an expired token, **When** a request is sent, **Then** the response is 401.
3. **Given** a revoked token, **When** a request is sent, **Then** the response is 401 immediately after revocation.
4. **Given** a token without `gate:read`, **When** a request is sent, **Then** the response is 403 with `WWW-Authenticate: Bearer error="insufficient_scope"`.
5. **Given** a token with `gate:read` and no `gate:ceiling:*` ability, **When** a request is sent, **Then** the
   response is 401 and the receipt records `token.no_ceiling`.

---

### User Story 3 - Issue tokens with an expiry and a ceiling (Priority: P1)

An administrator issues a token with one command. The command requires an expiry no longer than 90 days and a
ceiling. The token is shown once. Each issuance is recorded with who issued it, to whom, the ceiling and the
expiry.

**Why this priority**: Token issuance is the only way access is granted, so its rules are the access policy.

**Independent Test**: Run the command without an expiry (refused); with an expiry of 120 days (refused); with
30 days and ceiling internal (issued, shown once, receipt written).

**Acceptance Scenarios**:

1. **Given** no `--expires` option, **When** the command runs, **Then** it exits with an error and issues nothing.
2. **Given** `--expires=120d`, **When** the command runs, **Then** it exits with an error naming the 90-day maximum.
3. **Given** `--ceiling=internal --expires=30d`, **When** the command runs, **Then** a token is issued, shown once,
   and a receipt records issuer, holder, ceiling and expiry.
4. **Given** an ability outside the package set is requested, **When** the command runs, **Then** it is refused.

---

### User Story 4 - See and revoke active tokens (Priority: P2)

An administrator lists active tokens with holder, ceiling, expiry and issue date, and revokes one by identifier.
Revocation takes effect at the next request.

**Why this priority**: People leave teams and tokens leak; the administrator must be able to cut access quickly.

**Independent Test**: Issue two tokens; list shows both; revoke one; list shows one; a request with the revoked
token gets 401.

**Acceptance Scenarios**:

1. **Given** active tokens, **When** the list command runs, **Then** each token shows holder, ceiling, expiry and
   issue date, and no token value.
2. **Given** a token identifier, **When** revoke runs, **Then** the token is removed and the revocation is
   recorded.
3. **Given** a revoked token, **When** a request is sent, **Then** it is refused with 401.

---

### User Story 5 - Refuse foreign origins (Priority: P2)

Requests whose Origin header is not on the allowlist are refused. Requests without an Origin header are accepted
when they present a valid token, because non-browser clients send no Origin.

**Why this priority**: Protects against a web page in the user's browser driving the server with the user's
token (DNS rebinding and cross-site requests).

**Independent Test**: Send a request with a foreign Origin and a valid token; expect refusal. Send with no Origin
and a valid token; expect success.

**Acceptance Scenarios**:

1. **Given** an Origin not in the allowlist, **When** a request is sent, **Then** it is refused with 403.
2. **Given** no Origin header and a valid token, **When** a request is sent, **Then** it is served.
3. **Given** an allowlisted Origin, **When** a request is sent, **Then** it is served.

---

### Edge Cases

- A token issued with an expiry that passes while a session is open: the next request is refused with 401.
- A ceiling higher than the class of a configured field: the field is dropped by spec 002 rules (ceiling
  check), not by a token rule.
- A token identifier that does not exist on revoke: the command reports it and exits non-zero; nothing changes.
- Two administrators revoke the same token at once: both succeed; the second reports it was already revoked.
- The `clientInfo` in initialize claims a higher ceiling: ignored; the ceiling is the token's (D24).
- A token shown in terminal history: the listing never displays the token value; only the identifier.
- Clock skew between servers: expiry is checked against the server clock in UTC.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The HTTP transport MUST expose the same tools as the stdio transport, with the same
  visibility and firewall rules.
- **FR-002**: Every HTTP request MUST present a token. Requests without a valid token MUST receive 401 with a
  `WWW-Authenticate` header.
- **FR-003**: A token MUST be refused when it is expired, revoked, or lacks the `gate:read` ability.
- **FR-004**: A token MUST carry exactly one `gate:ceiling:<class>` ability, where class is `public`,
  `internal` or `personal`. A token without one MUST be refused with 401 and receipt reason
  `token.no_ceiling`. The class `secret` is not a valid ceiling: secret fields never leave the server under any
  ceiling (spec 002, research R3).
- **FR-005**: Tokens MUST be issued only by the `gate:token` command. No other path issues a token.
- **FR-006**: `gate:token` MUST require `--expires` and MUST refuse any value above 90 days.
- **FR-007**: `gate:token` MUST accept only abilities from the package set (`gate:read`, `gate:ceiling:public`,
  `gate:ceiling:internal`, `gate:ceiling:personal`).
  Any other ability MUST be refused.
- **FR-008**: `gate:token` MUST show the token value once, at issuance, and MUST NOT display it again.
- **FR-009**: Each issuance MUST be recorded in a receipt with the issuer, the holder, the ceiling and the
  expiry. The token value MUST NOT be recorded.
- **FR-010**: `gate:token --list` MUST show identifier, holder, ceiling, expiry and issue date for active tokens,
  and MUST NOT show any token value.
- **FR-011**: `gate:token --revoke <id>` MUST remove the token and record the revocation. A revoked token MUST
  be refused at its next request.
- **FR-012**: The ceiling for HTTP MUST come from the token's ability. A value the client sends in `clientInfo`
  MUST NOT change the ceiling.
- **FR-013**: The HTTP transport MUST remain available when the application runs in production, for requests
  with a valid token. The stdio transport remains refused in production (spec 001).
- **FR-014**: The HTTP transport MUST check the Origin header against an allowlist from configuration. A foreign
  Origin MUST be refused with 403. The package adds this check itself; laravel/mcp does not validate Origin
  (research R5).
- **FR-015**: Token lifetime MUST be checked against the server clock in UTC.
- **FR-016**: The 401 and 403 responses MUST NOT reveal which check failed beyond the status and the
  `WWW-Authenticate` header.
- **FR-017**: Each token MUST carry a holder name and an identifier of the issuing person, both provided at issuance.

### Key Entities

- **Token**: identifier, holder, issuer, abilities (`gate:read` and one `gate:ceiling:<class>`), issue date, expiry,
  revoked flag. The token value itself is stored only as a one-way hash.
- **Issuance receipt**: issuer, holder, ceiling, expiry, identifier.
- **Origin allowlist**: list of exact origins from configuration.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In 100% of tests, requests without a valid token, with an expired token, or a revoked token receive 401,
  and a token without `gate:read` receives 403.
- **SC-002**: In 100% of tests, `gate:token` without `--expires` or with more than 90 days issues no token.
- **SC-003**: In 100% of tests, no token value appears in any listing, receipt or log line.
- **SC-004**: In 100% of tests, a revoked token is refused at the next request.
- **SC-005**: In 100% of tests, a client-supplied ceiling in `clientInfo` leaves the effective ceiling unchanged.
- **SC-006**: In 100% of tests, a request with a foreign Origin is refused with 403.
- **SC-007**: A new administrator can issue, list and revoke a token within 5 minutes using the command help alone.

## Assumptions

- Specs 001–004 are complete. The stdio transport, tool registry, firewall and receipts already exist.
- Authentication uses Laravel Sanctum personal access tokens; the package adds only the abilities and the
  issuance command. The package does not replace Sanctum's storage.
- The maximum expiry of 90 days follows the roadmap (D34); the Origin allowlist is configured per application.
- The full OAuth authorization flow of the MCP specification is out of scope until after 1.0; clients present the
  token in the standard bearer header.
- Holder and issuer are free-text names entered at issuance; no directory integration in this feature.
- `secret` is not a ceiling (decided at planning, research R3); the Origin check is added by this package (research R5).

## Amendments (review fixes, 2026-11-01)

- Token expiry fails closed: an unexpected expiry value means the token is expired.
- Over HTTP, session pseudonyms persist in the cache store (`cache_store`) with a one-hour sliding TTL.

## Amendments (review fixes round 2, 2026-11-01)

- A valid token without `gate:read` or with an unknown ability gets `403` with `WWW-Authenticate: Bearer error="insufficient_scope"`. A valid token with no or several ceilings gets `401` with `WWW-Authenticate: Bearer` (see the 2026-11-01 TOK-401 amendment). `401` is only for a missing, invalid, expired or revoked token, and for a token with no or several ceilings.
- The HTTP guard and the refusal renderables are registered on every boot; only the `Mcp::web` route is skipped when routes are cached.
- A token's caller id is `token:<id>`; `gate:token` prints it with a hint to add it under `callers`, and `gate:token --list` has a `caller` column. Token checks use the token model's own connection.
- Revocation keeps the issuer row and sets its `token_id` to null.

## Amendments (batch 3, 2026-11-01)

- `http.refusals_per_minute` (default 60, cap 600): the `GateRefusalThrottle` middleware runs first on the HTTP route
  and counts 401 and 403 refusals per (client IP, presented credential) in the gate cache store over 60 seconds.
  Successful requests never count.
- Past the limit that pair gets `429` with `Retry-After: 60` and a generic JSON-RPC body, before any origin, token or
  database work; one `http.throttled` receipt (caller `unknown`, no IP, no hash) per key per window.
- A coarse per-IP counter (min(10 x limit, 6000)) only bounds log volume and never denies: above it anonymous refusals
  are not recorded except one summary receipt per IP per window; known-token refusals are always recorded.
- The origin check looks the token up only for a well-formed bearer (at most 512 bytes).
- The package does not deny service by IP and does not stop token rotation; floods are for the reverse proxy or WAF.
- Behind a proxy the IP is right only if the host configures trusted proxies.

## Amendments (TOK-403, 2026-11-01)

- A valid token without `gate:read` answers 403 `insufficient_scope`, the same as an unknown ability; the spec and contract had disagreed (401 vs 403) and the code and tests already return 403.
- TOK-401 (2026-11-01): a token without a ceiling (no `gate:ceiling:*`, or more than one) answers `401` with `WWW-Authenticate: Bearer`, not `403`. The `gate:read` and unknown-ability cases keep `403`.

## Amendments (WWW-AUTH, 2026-11-01)

- `WWW-Authenticate` per RFC 6750 section 3.1, with no `realm` parameter: no token at all → `Bearer`; expired, revoked, unknown or no-ceiling token → `Bearer error="invalid_token"`; valid token without `gate:read` or with an unknown ability → `Bearer error="insufficient_scope"` (403). The laravel/mcp `realm="mcp"` header is not used. FR-016 allows the header to name the class of refusal.

## Amendments (HOLE-HTTP, 2026-11-01)

- The refusal counter (`http.refusals_per_minute`) bumps under a per-key cache lock (3 second wait), so concurrent refusals on a file store are counted exactly and the 429 trips on time; a lock timeout skips the count only, and that refusal is still refused (never admitted).

## Amendments (HOLE-HTTP-FIX, 2026-11-01)

- The refusal counter never blocks the refusal path (one non-blocking lock attempt for the per-credential key; a failed lock falls back to a best-effort unlocked count, which can undercount under contention); the per-IP counter is not locked; the throttle trips at `>=` the limit with one receipt per key per window.
## Amendments (HOLE-SECRET, 2026-11-01)

- A token never carries the `secret` ceiling. `gate:token --ceiling=secret` is refused before anything is issued, and the token path refuses a token that carries `gate:ceiling:secret` (not in the token ability package): `403` `insufficient_scope`, receipt reason `token.ability`, and the handler never runs.
