# Specification Quality Checklist: Laravel Sources

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-10-30
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] Focused on user value (structure questions answered without data)
- [x] Written for non-technical stakeholders (scenarios read as journeys)
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
- [x] Requirements are testable and unambiguous
- [x] Success criteria are measurable
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded (OpenAPI, query catalog, scan/classify deferred)
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] Feature meets measurable outcomes defined in Success Criteria

## Notes

- Decisions relied on: D26, D27, D28, D30 in `/Users/pavlo/Desktop/context-gate-roadmap.html`. Denylist and
  secret word list reviewed as in the roadmap.
- FR-006 refers to SC-002 for verification; kept as is.

## Quickstart run (T051, batch 003-P)

- Run on master f9aa7e6 with batch 003-P. The repository fixture has no `artisan` entry point, so the run used a
  scratch copy of `tests/Fixtures/app` (with `docs/guide.md`, a `docs/link` symlink to `../.env`, and a fake `.env`
  holding `FAKE_FIXTURE_ONLY`) and a testbench kernel that starts `mcp:start context-gate` over stdio with the
  source config set in the child process. Nothing in the repository was changed by the run.
- Section 1 (routes): FAIL. `api/orders` is listed and the 32-character segment shows as `<SECRET>`. The
  `telescope` route is also listed: the default exclude `telescope/*` does not match the bare URI `telescope`.
  The framework's `storage/{path}` routes are listed too (fixture environment).
- Section 2 (schema): PASS. `email` and `name` are `personal`, there is no `password`, and `hidden_columns` has
  detail `1`. The query log holds four statements, all schema introspection; none reads rows.
- Section 3 (config): PASS. `database.host` shows `env:DB_HOST`, `services.stripe.secret` shows `env:<hidden>`,
  and no value is in the response. The full config tree is truncated at the per-call limit; both keys were in the
  returned part.
- Section 4 (docs): FAIL on the step name. `docs/guide.md` is returned wrapped as untrusted, and `.env` is refused
  with step `denylist` although it is listed. `docs/link` is refused with `source.refused`, step `denylist`; the
  quickstart expects `realpath`. The refusal itself and the absence of any path in the response hold.

## Quickstart run (batch QS-357)

Run on 2026-11-01 on `tests/Fixtures/Quickstart` (config in `config/context-gate.php`; models User and Order). A
Testbench-style runner boots the package on that base path with a sqlite file outside the repository, `APP_ENV=local`,
the OS user as the caller, and fake values only (`FAKE_FIXTURE_ONLY`). No network, no `.env` read. `curl` was not used.

- [x] Section 1 (routes): FAIL on the literal expectation. `gate:preview routes.list` exit 0 printed `api/orders` and
  `invite/<SECRET>` (the 32-character segment is masked), and the telescope route is absent. It also printed two
  `storage/{path}` rows (Laravel's default local-disk route) and three `mcp/context-gate` rows (the package's own
  route), so the output is not "the public route only". Found, not fixed.
- [x] Section 2 (schema): PASS. `gate:preview schema.describe` exit 0 printed `name` and `email` with class `personal`,
  then `hidden_columns` with detail `1`; `password` absent. The query log has no row select on `users`.
- [ ] Section 3 (config): FAIL. `gate:preview config.keys` exit 0 printed `services.stripe.secret` with
  `env:<hidden>`, with `truncated: true` at 248 rows. The `database.host` row (env `DB_HOST`) is not in the result, so
  `env:DB_HOST` was not observed. Also observed: keys with a hyphen are masked, so `context-gate.enabled` shows as
  `<SECRET>.enabled`. Found, not fixed.
- [x] Section 4 (docs): PASS with a step-name note. `docs/guide.md` returned with `untrusted: true`. With a target
  present, `docs/link` (symlink to `../.env`) was refused with `refusal_step: denylist`, and `.env` was refused with
  `denylist` both unlisted and listed (the listed case used a runner override, not a config edit). With a dangling link
  (no target file) the step was `realpath`, not `denylist`.
- [x] Section 5 (gates): `composer test`, `composer stan`, `composer style` and `composer length` exit 0 on the branch
  (1047 tests, 7 skipped).

