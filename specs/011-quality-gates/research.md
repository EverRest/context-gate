# Research: Quality Gates and Maintenance Hygiene

State checked at HEAD `d744441` (2026-11-01). Commit `59777d9` already did part of this feature; each item below says
what exists and what is left.

## R1. Mutation tool

**Decision**: `infection/infection` as a dev dependency (`require-dev`), run by a composer script `mutation` and its
own CI job on PHP 8.3 with locked dependencies.

**Rationale**: Infection is the standard PHP mutation tool, understands PHPUnit 11/12 and pcov, writes GitHub
annotations, and was already named as the dev-only mutation tool in `specs/001-mcp-stdio-foundation/plan.md`
(constitution X check of spec 001). Principle X restricts *runtime* dependencies; a dev dependency is not in the
distribution archive (`.gitattributes export-ignore` covers `tests/`, and `require-dev` is never installed by a
consumer). Principle IX ("each rule has a test that fails before its implementation exists") is only checkable at
scale by mutation.

**Alternatives rejected**: a hand-written mutator script (reinvents Infection, worse diagnostics); `humbug/humbug`
(abandoned, predecessor of Infection); running mutation inside `composer test` (FR-011, principle IX: `composer test`
stays fast and network-free).

## R2. Scoping Infection to the security paths (one explicit list)

**Decision**: The security scope is one list, the custom key `contextGate.scope` in `infection.json5` at the
repository root: whole directories plus single files inside mixed directories. Concretely:

| Scope entry (FR-002) | Path |
|---|---|
| Pipeline stages | `src/Pipeline` |
| Privacy (pseudonyms, redaction) | `src/Privacy` |
| HTTP auth, origin, refusal throttle | `src/Http` |
| Egress (URL guard, address ranges, pinned client) | `src/Source/Fetch` |
| Document path policy, example policy, sensitive names | `src/Source/DocsPathPolicy.php`, `src/Source/ExamplePolicy.php`, `src/Source/SensitiveNames.php` |
| Call path (visibility, admission, arguments) | `src/Server/PolicyStep.php`, `src/Server/Admission.php`, `src/Server/ArgumentsStep.php`, `src/Server/ArgumentValidator.php`, `src/Server/ToolRegistry.php`, `src/Server/Canaries.php`, `src/Server/ContractGuard.php` |
| Tool policy | `src/Policy` |

Infection's `source.directories` takes directories; the single files are passed with `--filter` (comma-separated
relative paths) in the composer script. To keep *one* list, the composer script does not hard-code them: a tiny
`scripts/mutation.php` reads the list from `infection.json5` key `contextGate.scope` (a custom key Infection ignores),
sets `source.directories` to `["src"]` and passes the whole list as `--filter`. T001 verifies `--filter` semantics on
the installed Infection version (path-prefix match on directories and exact match on files); if a directory prefix
does not match, the fallback is to list each file of those directories, still in the same one key.

**Rationale**: FR-002 asks for one explicit list in the repository; a reviewer changes one key.

**Alternatives rejected**: `source.excludes` to carve the scope out of `src` (an exclusion list is the inverse of
the scope and reads like an ignore list, FR-006); listing the files in `composer.json` (second place to look);
whole `src/` (too slow, most mutants not on the security path, and the 90% minimum would be meaningless).

## R3. Minimum score, no ignore list, no lowering

**Decision**: `infection.json5` sets `minMsi: 90` (MSI over all mutants, so uncovered mutants count as escaped) and
no `minCoveredMsi`. Timed-out mutants count as killed (Infection default; spec edge case). A guard script
`scripts/check-mutation-config.php` (run first in the mutation job and by the `refs` gate in R6) fails when:

- `infection.json5` holds `ignore`, `ignoreSourceCodeByRegex`, or a mutator set other than the default profile;
- any file under `src/` holds `@infection-ignore-all` or `@infection-ignore`;
- `minMsi` in `infection.json5` is below 90 (the floor constant in the script);
- `.github/workflows/ci.yml` or `composer.json` passes `--min-msi`, `--min-covered-msi`, `--ignore-msi-with-no-mutations`
  or reads a threshold from an environment variable.

**Rationale**: FR-003, FR-004, FR-006. Raising is a reviewed edit of `infection.json5` and of the constant; lowering
needs both edits plus a reason in the change.

**Alternatives rejected**: 100% with an allowlist of equivalent mutants (rejected in Clarifications).

## R4. Coverage driver and floor

**Decision**: `pcov` in CI (`shivammathur/setup-php` `coverage: pcov`) for both the coverage and mutation jobs.
Composer script `coverage` = `phpunit --coverage-clover build/coverage/clover.xml` followed by
`php scripts/coverage-floor.php build/coverage/clover.xml`. The floor is a constant `COVERAGE_FLOOR = 92.0` in the
script (FR-004: defined in the repository, not in the job). The script prints `line coverage X% (floor 92%)`, appends
the same line to `$GITHUB_STEP_SUMMARY` when set, and exits 1 when the clover file is missing or has zero statements
(edge case: no driver never passes).

**Rationale**: Line coverage only (FR-007); pcov is faster than xdebug and is what the 59777d9-removed job used.
Serial `phpunit` not `paratest` for the clover run: one report, no merge step.

**Alternatives rejected**: xdebug (slower, same numbers for lines); a third-party coverage action (network, extra
supply chain); Infection's own coverage (only covers the scope).

**Open fact**: current line coverage is unknown. T005 measures it. If it is below 92%, the floor is not lowered: tests
are added first (the spec default stands) and the job is enabled when green.

## R5. Finding records

**Decision**: `tests/findings.php` returns `finding-id => ['fixed' => 'YYYY-MM-DD', 'summary' => '...']`. Each guarding
test carries `#[Group('finding-<id>')]`. `tests/Unit/FindingRecordsTest.php` (runs in `composer test`) reflects over
every test class under `tests/` and fails, naming the finding, when a recorded id has no test method with its group,
or when a test is marked with a `finding-*` group that is not recorded. "Does not run": a group on a method that is
skipped by default (`Concurrency`, install wrapper) fails the check unless the record says `'opt_in' => true`.

FR-009 (the test fails when the fix is undone) is checked by hand at recording time; the recording task names the
revert and the observed failure in the record's `summary`. Findings inside the security scope are additionally
covered by the mutation job.

**Seed**: the existing review tests (`tests/Contract/ReviewFixesRuntimeTest.php`, `tests/Contract/ReviewRound2Test.php`,
`tests/Unit/Http/RefusalLedgerLockTest.php`, `tests/Unit/Server/ExtensionPointsTest.php`) are recorded first.

**Alternatives rejected**: a markdown register (not checkable); finding id in the test name only (a rename silently
drops the link).

## R6. Spec-reference check for `src/` comments

**Decision**: `scripts/check-spec-refs.php` tokenizes every `src/**/*.php` with `token_get_all` (stdlib, no
dependency) and scans only `T_COMMENT` and `T_DOC_COMMENT` tokens for:

```
\bspec\s+\d{3}\b | \b(FR|SC|NFR)-\d{3}\b | \bUS\d+\b | \bT\d{3}\b | \bresearch\s+R\d+\b | \bR\d{1,2}\b | \bD\d{2}\b
```

It prints `path:line: <match>` per hit and exits 1 when any. Composer script `refs`; added to `composer ci`. False
positives are fixed by narrowing the pattern (no per-line opt-out, spec edge case). `tests/` and `specs/` are not
scanned (FR-016).

**Count today**: 396 matches in 108 files (rough regex over all of `src/`, comments and code). The rewrite is split by
directory in tasks.md.

## R7. CI wiring and what 59777d9 left

- CI today runs `test`, `stan`, `style`; it does **not** run `composer length`. The spec assumes "the same CI job as
  the file-length check"; there is none. Decision: add `composer length` and `composer refs` steps to the PHP 8.3
  locked leg of the `test` job.
- The commented coverage job was removed by `59777d9`; it is re-added as job `coverage`.
- The Redis `services:` block in the `test` job is still commented; the coverage and mutation jobs do not need Redis
  (Redis tests stay opt-in, spec edge case).

## R8. Compatibility scaffolding (US4): already satisfied

Verified at HEAD:

| Requirement | State | Evidence |
|---|---|---|
| FR-012 changelog without "Breaking" / "superseded" | satisfied | `grep -n "Breaking\|superseded" CHANGELOG.md` is empty; 0.1.0 entry lists current behaviour |
| FR-013 `docs/upgrading.md` deleted, no links | satisfied | removed in `59777d9`; no `upgrading` match in `README.md`, `docs/`, `CHANGELOG.md` |
| FR-014 interfaces bound directly, no concrete-name bindings | satisfied | `src/ContextGateServiceProvider.php` binds via `$app->build(...)`; `tests/Unit/Server/ExtensionPointsTest.php` `test_binding_the_concrete_class_does_not_replace_the_interface`; `docs/extending.md` lines 24-25 |

Only verification tasks remain. The spec edge case about an uncommitted README change is moot (no link exists).

## R9. The ~12 review survivors (FR-010)

The review's survivor list is not in the repository (the 011 checklist notes this). Decision: the first mutation run
over the scope (T008) is the baseline; every survivor it reports is either killed by a new test or the code is
simplified so the mutant no longer exists, and each kill is recorded as a finding (`mutation-<file>-<line>`). The
minimum score is enforced (job made required) only after that set is empty or the score is at least 90% with each
review-era survivor killed.
