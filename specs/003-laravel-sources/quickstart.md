# Quickstart: Laravel Sources

Requires specs 001 and 002.

## 1. Routes (User Story 1)

Add a public `api/orders` route and a `telescope` route. Call `routes.list`. Expected: the public route only,
with method, uri, name, controller, rule names and middleware names.

Add a route with a 32-character random segment. Expected: the segment appears as `<SECRET>`.

## 2. Schema (User Story 2)

Model `User` with `email` (personal), `password` (secret), `name` (personal). Call `schema.describe`.
Expected: `email` and `name` with classes; no `password`; `hidden_columns: 1`.

Check the query log: no row query was executed.

## 3. Config (User Story 3)

Set `env_map` for `database.host` → `DB_HOST` and `services.stripe.secret` → `STRIPE_SECRET`. Call
`config.keys`. Expected: `env:DB_HOST` and `env:<hidden>`; no values in the response.

## 4. Docs (User Story 4)

Create `docs/guide.md` and a symlink `docs/link` → `../.env`. Call `docs.read` on each.
Expected: `guide.md` returned wrapped as untrusted; `docs/link` refused with `source.refused`, step
`realpath`; `.env` refused with step `denylist`, even when listed in config.

## 5. Gates

```bash
composer test && composer stan && composer style
```

Expected: exit code 0 for each.
