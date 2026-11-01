# Quickstart: One Path to Settings

Run from a host application with the package installed and the config published
(`php artisan vendor:publish --tag=context-gate-config`).

## 1. One path (US2)

```bash
vendor/bin/phpunit --filter SingleSettingsPathTest      # in the package checkout
grep -rn "config('context-gate" src | grep -v "^src/Settings/"; echo "matches=$?"   # expect matches=1
```

## 2. Check and runtime agree (US1)

Set `'http' => ['path' => 123]` in `config/context-gate.php`:

```bash
php artisan gate:check; echo "exit=$?"
```

Expected: FAIL naming `context-gate.http.path`, exit 1. Over stdio (`php artisan mcp:start context-gate`), any
`tools/call` answers the generic failure and the receipt on stderr has `"reason":"config.invalid"`; `tools/list` is
empty. Restore the value; `gate:check --json` shows `effective.limits` equal to the strict profile values.

## 3. Built-in source tables (US3)

With `'classification' => []`, enable `routes.list` for a caller and call it: columns come back `internal`.

Hide a column:

```php
'classification' => ['source_routes' => ['middleware' => false]],
```

Call `routes.list`: no `middleware` column; the receipt lists `field.unlisted`. Then set
`'source_routes' => ['uri' => 'public']`: `gate:check` fails naming `context-gate.classification.source_routes.uri`.
Add `'source_custom' => ['a' => 'internal']`: fails naming `context-gate.classification.source_custom`.

## 4. Receipts prove the values (US4)

Call the same tool with `profile` `strict` and then `balanced` (non-production `APP_ENV`, restart between): the
receipts' `rules.classification` differ. Over HTTP, call with a `public` token and an `internal` token: the hashes
differ.

## 5. Gates

```bash
composer style && composer stan && composer length && composer refs && composer test
```
