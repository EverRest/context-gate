# Quickstart: OpenAPI Source and Developer Tooling

Requires specs 001–003.

## 1. Local document (User Story 1)

Place `api/openapi.json` (3.1, two endpoints, one example with a seeded email). Configure
`sources.openapi.paths` to allow it. Call `openapi.endpoints` with `{"path": "api/openapi.json"}`.
Expected: both endpoints with schemas; the example is absent; the email appears nowhere.

Set `examples: redacted`. Expected: the example appears, and the email is replaced by its placeholder.

## 2. Network refusals (User Story 2)

With an empty allowlist, call with any URL. Expected: `egress.denied`. Add `docs.example.com` to the allowlist;
point a fixture resolver at `169.254.169.254`. Expected: `egress.denied`. Serve a 302 redirect. Expected:
`source.redirect`.

## 3. Catalog draft (User Story 3)

Run `php artisan gate:classify`. Expected: `app/Gate/classification.php` with `personal // suggested` for
`email`, `secret // suggested` for `password`, `secret // needs decision` for other columns. Run again without
`--force`: expected refusal and no change.

## 4. Preview (User Story 4)

Run `php artisan gate:preview orders.status --args='{"status":"paid"}'`. Expected: the exact JSON the tool would
return, then `Dropped:` lines. A receipt with `source: preview` appears in the log.

## 5. Canary (User Story 5)

Run `php artisan gate:canary`. Expected: two values and placements; `git status` shows no changed file.

## 6. Gates

```bash
composer test && composer stan && composer style
```

Expected: exit code 0 for each.
