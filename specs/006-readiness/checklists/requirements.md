# Specification Quality Checklist: Release Readiness

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-10-30
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] Focused on operator and maintainer outcomes
- [x] Written for non-technical stakeholders (scenarios read as journeys)
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
- [x] Requirements are testable and unambiguous
- [x] Success criteria are measurable
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded (no new tools, sources, Lumen, OAuth or write module)
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] Feature meets measurable outcomes defined in Success Criteria

## Notes

- Publication is excluded by FR-020 and the Assumptions; this spec prepares 0.1.0 only.
- SECURITY.md reporting address is a placeholder that blocks the release check (Assumptions). Owner action required.

## Quickstart record (T069, batch 006-P, 2026-11-01)

Run in a scratch copy of the worktree outside the repository, against the Testbench skeleton with a file-backed
SQLite database. No `composer install` and no network.

- [x] 1. Audit: PASS. `gate:audit` and `gate:audit --json` exit 0 and the JSON is valid. The stock configuration
  defines no tools, so the enabled-tool and disabled-tool listing (risk, hashes) was not exercised.
- [x] 2. Doctor: PASS. `gate:check` exits 0 after `migrate`; the canary WARN (no canary values set) is not a FAIL.
  One injected error (`firewall.limits.calls_per_minute` set to 999, above its cap) gives FAIL naming the key and
  exit 1. Before `migrate`, `gate:check` fails on the missing `gate_access_tokens` table, as designed.
- [ ] 3. Installation on Laravel 12 and 13: NOT RUN. `scripts/install-test.sh` creates fresh applications with
  `composer create-project`, which needs the network; this batch does not allow it. Needs a CI run or a maintainer run.
- [x] 4. Documentation: PASS. `composer test -- --filter DocsConfigSyncTest`: OK (2 tests, 6 assertions).
- [ ] 5. Release dry run: NOT RUN. It needs the release workflow on a pushed branch. Pushing is out of scope for
  this batch, and publication stays with the maintainer (T071).
- [x] 6. Gates: PASS. `composer test`, `composer stan`, `composer style` and `composer length` all exit 0.

Publication (T071): not done. The release waits for the maintainer's explicit approval (FR-020).

## Quickstart run (batch QS-357)

Run on 2026-11-01 on `tests/Fixtures/Quickstart`, the same runner as spec 003 (fake values, no network, no `.env` read).

- [x] Section 1 (audit): PASS. `gate:audit` exit 0 listed `routes.list`, `schema.describe`, `config.keys` and `docs.read`,
  each with `risk: unset` (no tool entry has a risk key), `ceiling: internal`, field classes, contract and rule hashes.
  `Not enabled:` listed `orders.status` by name. `gate:audit --json | python3 -m json.tool` exit 0, then `valid`.
- [x] Section 2 (doctor): PASS with WARN. Baseline `gate:check` exit 0 printed 10 PASS and 2 WARN, not "all PASS":
  canaries (no canary values set) and callers (tokens 1, 3 and 4 have no callers entry). With a limit above its cap
  (runner override `firewall.limits.per_call_bytes` = 999999999), `gate:check` exit 1 printed
  `FAIL context-gate.firewall.limits.per_call_bytes: ... above its code cap`, naming the key. The same FAIL line is
  printed twice. Observed in fixture setup: a disabled tool that declares a contract is checked by `gate:check`, so an
  unclassified contract column fails the check while the tool is disabled.
- [ ] Section 3 (install on 12 and 13): NOT RUN. `scripts/install-test.sh` runs `composer create-project`, which needs
  the network; the batch rules exclude it.
- [x] Section 4 (documentation): PASS. `composer test -- --filter DocsConfigSyncTest` exit 0, "OK (2 tests, 6 assertions)".
- [ ] Section 5 (release dry run): NOT RUN. It needs the release workflow on a branch in GitHub Actions, which is not
  available in this run.
- [x] Section 6 (gates): `composer test`, `composer stan`, `composer style` and `composer length` exit 0 on the branch.

