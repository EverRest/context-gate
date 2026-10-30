# Quickstart: Release Readiness

Requires specs 001–005.

## 1. Audit (User Story 1)

```bash
php artisan gate:audit
php artisan gate:audit --json | python3 -m json.tool > /dev/null && echo valid
```

Expected: enabled tools with risk, ceiling, classes, contract and hashes; disabled tools by name; valid JSON.

## 2. Doctor (User Story 2)

```bash
php artisan gate:check; echo "exit=$?"
```

Expected on a correct installation: all PASS, exit 0. Then introduce one error (for example set a limit above its
cap) and expect FAIL with the key named and exit 1.

## 3. Installation on two versions (User Story 4)

```bash
scripts/install-test.sh 12
scripts/install-test.sh 13
```

Expected: both print `install ok`, `gate:check ok`, `quickstart ok`.

## 4. Documentation (User Story 3)

```bash
composer test -- --filter DocsConfigSyncTest
```

Expected: every key in `config/context-gate.php` is documented and the reverse.

## 5. Release dry run (User Story 5)

Run the release workflow with `publish: false` on a branch. Expected: changelog, version and placeholder guards
run; the archive is uploaded; no release is created. While `<REPORTING_ADDRESS>` is in `SECURITY.md`, the
placeholder guard fails.

## 6. Gates

```bash
composer test && composer stan && composer style
```

Expected: exit code 0 for each.
