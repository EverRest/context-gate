# Data Model: Quality Gates and Maintenance Hygiene

No runtime data. All entities are repository files read by CI and by `composer test`.

## Security scope

| Field | Type | Rule |
|---|---|---|
| `contextGate.scope` in `infection.json5` | list of relative paths (directory ending in `/`, or `.php` file) | each path exists under `src/`; must include every FR-002 group (research R2) |

Validation: `scripts/check-mutation-config.php` fails on a missing path or a missing FR-002 group
(`tests/Unit/Scripts/MutationConfigTest.php` covers it).

## Mutation thresholds

| Field | Where | Value | Rule |
|---|---|---|---|
| `minMsi` | `infection.json5` | 90 | at least `MUTATION_FLOOR` |
| `MUTATION_FLOOR` | `scripts/check-mutation-config.php` | 90 | changed only by a reviewed edit |
| forbidden keys | `infection.json5` | `ignore`, `ignoreSourceCodeByRegex`, custom mutator profiles | presence fails |
| forbidden annotations | `src/**` | `@infection-ignore-all`, `@infection-ignore` | presence fails |
| forbidden flags | `composer.json`, `.github/workflows/ci.yml` | `--min-msi`, `--min-covered-msi`, `--ignore-msi-with-no-mutations`, env-sourced thresholds | presence fails |

## Coverage floor

| Field | Where | Value |
|---|---|---|
| `COVERAGE_FLOOR` | `scripts/coverage-floor.php` | 92.0 (percent of executable lines in `src/`) |
| input | `build/coverage/clover.xml` | missing file or 0 statements is a failure |

Output line: `line coverage <measured>% (floor 92%)`, also appended to `$GITHUB_STEP_SUMMARY` when set.

## Finding record

`tests/findings.php` returns `array<string, array{fixed: string, summary: string, opt_in?: bool}>`.

| Field | Type | Rule |
|---|---|---|
| key (finding id) | `[a-z0-9-]+` | unique; matches a `#[Group('finding-<id>')]` on at least one test method |
| `fixed` | `YYYY-MM-DD` | date the fix was merged |
| `summary` | string | one line: the rule the fix restored, and the hand revert check (FR-009) |
| `opt_in` | bool, default false | true only when the guarding test is in an opt-in suite (Concurrency) |

State: recorded → guarded (group found, test runs by default or is opt-in as declared). Any other state fails
`tests/Unit/FindingRecordsTest.php`.

## Spec-reference pattern

Scanned tokens: `T_COMMENT`, `T_DOC_COMMENT` of `src/**/*.php`. Pattern: research R6. Output per hit:
`<path>:<line>: <matched text>`.
