# Specification Quality Checklist: HTTP Access and Tokens

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-10-30
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] Focused on user value (on-call access, controlled issuance, fast revocation)
- [x] Written for non-technical stakeholders (scenarios read as journeys)
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
- [x] Requirements are testable and unambiguous
- [x] Success criteria are measurable
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded (full OAuth flow deferred)
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] Feature meets measurable outcomes defined in Success Criteria

## Notes

- Decisions relied on: D20, D24, D34, D35 (revised to Sanctum after the Lumen drop, D36) in
  `/Users/pavlo/Desktop/context-gate-roadmap.html`.
- FR-004 excludes `secret` as a ceiling (decided at planning, research R3). The Origin check is added by the package
  (laravel/mcp does not validate Origin, research R5).

## Quickstart run (T054, batch 005-P)

Run on a fresh `laravel/laravel` application (Laravel 13.39.0, PHP 8.5.0) in a scratch directory outside the
repository. `everrest/context-gate` was required as `@dev` from the worktree (batch/005-P) as a path repository.
The application's own `.env` was not created by the install, so none was read or printed. The fixture's
SQLite database was created with `touch` and migrated. Gates ran in the worktree. Code at the worktree HEAD 842d24d.

- [x] Section 1, issue a token: PASS. `gate:token` exit 0, token shown once, one `gate_token_issuers` row, a
  `token.issued` receipt on stderr without the token value. Without `--expires`: exit 1, nothing issued. With
  `--expires=120d`: exit 1 with the 90-day maximum.
- [x] Section 2, connect over HTTP: PASS. `initialize` returns 200 with a result. Repeated with `clientInfo.name`
  claiming a higher ceiling: `tools/list` is identical. Caveat: the fixture enables no tool, so both lists are
  empty; the ceiling filter itself is covered by `HttpCeilingFilterTest`.
- [x] Section 3, refusals: PASS. No `Authorization`: 401 with `WWW-Authenticate: Bearer`. Revoked token: 401
  (see section 4). Token with only `gate:read` (created in the scratch application by tinker, the fixture route
  the quickstart names): 401, receipt `token.no_ceiling`. Valid token with `Origin: https://evil.example`: 403
  (only the status line was checked, not the empty body).
- [x] Section 4, list and revoke: PASS. `--list` shows id, holder, issuer, ceiling, `expires_at` and `issued_at`,
  no token value. `--revoke=1` prints "Token 1 revoked." and exits 0; the same token then gets 401 on `initialize`.
  `--revoke=999` exits 1.
- [x] Section 5, production: PASS. With `APP_ENV=production`, `php artisan mcp:start context-gate` exits 1, writes
  nothing to stdout and prints the refusal on stderr. HTTP with a valid token returns 200.
- [ ] Section 6, gates: FAIL. `composer stan` exit 0, `composer style` exit 0 (no fix needed), `composer length`
  exit 0. `composer test` exit 1: one failure, `TokenEdgeCasesTest::test_expiry_is_compared_in_utc_when_the_app_runs_in_another_timezone`
  (T050, red). Cause: `expires_at` is stored without a zone in the writer's zone and read back in `app.timezone`,
  so with `app.timezone` at UTC+14 a token expiring at 21:52 UTC is read as expiring at 21:52 UTC+14, about 14 hours
  early. Fix is in `src/` (the stored or parsed expiry must be pinned to UTC); not made in batch 005-P.

## Quickstart run (batch QS-357)

Run on 2026-11-01 on `tests/Fixtures/Quickstart`, the same runner as spec 003 (in-process Testbench boot, sqlite file
outside the repository, fake values only, no network). HTTP steps ran as in-process kernel requests to
`/mcp/context-gate`, not with `curl`, which the batch rules exclude. The cache store is `array` because the fixture
has no cache table, so refusal counters are per process.

- [x] Section 1 (issue): PASS. `gate:token --holder="Ivan (on-call)" --issuer="Pavlo" --ceiling=internal --expires=30d`
  exit 0 printed "Token issued. The token value is shown once", caller key `token:1`. `gate_token_issuers` has one row
  (token_id 1, issuer Pavlo). Receipt `token.issued` has no token value. Without `--expires`: exit 1,
  "--expires is required and takes whole days". With `--expires=120d`: exit 1, "--expires must be at most 90d (the
  maximum)". Nothing was issued by either refusal.
- [x] Section 2 (connect): PASS in-process. POST with the token gave HTTP 200 and an initialize result (protocol
  2025-11-25). With `clientInfo.name` "ceiling-personal" it gave HTTP 200, and the `tools/list` result was identical
  before and after (routes.list, schema.describe, config.keys, docs.read).
- [x] Section 3 (refusals): mixed.
  - No `Authorization`: PASS. HTTP 401, `WWW-Authenticate: Bearer realm="mcp", error="invalid_token"`.
  - Valid token with `Origin: https://evil.example`: PASS. HTTP 403.
  - Token with only `gate:read` (fixture `fixture:token-read-only`): FAIL on the status. HTTP 403 with
    `WWW-Authenticate: Bearer error="insufficient_scope"`, body "Unauthenticated.", receipt `token.no_ceiling`. The
    quickstart says 401. `spec.md` line 207 and `GateAuthRefusal.php` say 403; `quickstart.md` line 30,
    `data-model.md` line 62 and `contracts/http-and-tokens.md` line 18 say 401. The documents disagree; code follows
    `spec.md`. Found, not fixed.
  - Revoked token: covered in section 4.
- [x] Section 4 (list and revoke): PASS. `gate:token --list` printed a table of id, holder, issuer, caller, ceiling,
  expires_at and issued_at, with no token value. `gate:token --revoke=2` printed "Token 2 revoked."; the same token
  gave HTTP 200 before and HTTP 401 `error="invalid_token"` after.
- [x] Section 5 (production): PASS. With production set, `mcp:start context-gate` over stdio exited 1 with stderr
  "context-gate: refusing to start over stdio in production; use authenticated HTTP." HTTP with a valid token gave 200.
  Production was set by a runner override of the detected environment, not by an `APP_ENV` value in `.env`.
- [x] Section 6 (gates): `composer test`, `composer stan`, `composer style` and `composer length` exit 0 on the branch.

