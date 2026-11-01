# Quickstart: Quality Gates and Maintenance Hygiene

Maintainer checks, from a clone with dev dependencies installed and pcov enabled (`php -m | grep pcov`).

## 1. Mutation gate (US1)

```bash
composer mutation; echo "exit=$?"
```

Expected: Infection runs only over the scope in `infection.json5` (`contextGate.scope`), prints MSI >= 90, exit 0.
Negative check: set `minMsi` to 100 in a scratch branch; expect exit 1, a list of escaped mutants by file, line and
mutator, and the guard script still passing. Set `minMsi` to 80; expect `scripts/check-mutation-config.php` to fail.

```bash
php scripts/check-mutation-config.php; echo "exit=$?"
```

Add `// @infection-ignore-all` to any file in `src/Pipeline`; expect exit 1 naming the file and line.

## 2. Coverage floor (US2)

```bash
composer coverage; echo "exit=$?"
```

Expected: `line coverage NN.N% (floor 92%)`, exit 0. Delete one unit test file in a scratch branch; expect exit 1 and
both numbers. Run without pcov (`php -d extension=... ` removed); expect exit 1 ("no coverage measured").

## 3. Finding records (US3)

```bash
vendor/bin/phpunit --filter FindingRecordsTest
```

Expected: pass. Remove the `#[Group('finding-...')]` attribute from one recorded test; expect failure naming the
finding id.

## 4. Spec references (US5)

```bash
composer refs; echo "exit=$?"
```

Expected: exit 0, no output. Add `// see FR-004` to any `src/` file; expect exit 1 and `src/...php:<line>: FR-004`.
A spec id in `tests/` is not reported.

## 5. Hygiene already done (US4)

```bash
test ! -e docs/upgrading.md && echo gone
grep -rn "upgrading\|superseded\|Breaking" README.md docs CHANGELOG.md; echo "matches=$?"   # expect matches=1
vendor/bin/phpunit tests/Unit/Server/ExtensionPointsTest.php
```

## 6. All gates

```bash
composer style && composer stan && composer length && composer refs && composer test
```

`composer test` must not run Infection or coverage (FR-011).
