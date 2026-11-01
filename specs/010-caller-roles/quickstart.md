# Quickstart: Caller Roles

From a host application with the package installed, migrations run, config published, and two tools enabled
(for example `routes.list` and `docs.read`).

## 1. Define a role and issue a token (US1)

```php
'roles' => [
    'support' => ['tools' => ['routes.list'], 'ceiling' => 'internal'],
],
```

```bash
php artisan gate:check; echo "exit=$?"                 # 0
php artisan gate:token --holder=helpdesk --issuer=ops --role=support --expires=30d
php artisan gate:token --list                          # role column = support, ceiling = internal
```

Call `tools/list` over HTTP with the printed token: only `routes.list`. Call `docs.read`: unknown-tool response; the
receipt on the `context-gate` channel has `"reason":"policy.hidden"` and `"role":"support"`.

Negative: `gate:token ... --role=nope` and `gate:token ... --role=support --ceiling=personal` both exit 1 and
`gate:token --list` shows no new row.

## 2. Ceiling narrowing (US2)

Issue with `--ceiling=public`; a call returns only public fields. Change the role ceiling to `personal`, restart:
still only public fields. Set role ceiling `secret`: `gate:check` fails on `context-gate.roles.support.ceiling`.

## 3. Unknown role (US3)

Rename `support` to `helpdesk` in config. `gate:check` warns naming the token id, holder and `support`. The token's
`tools/list` is empty; any call answers as unknown with `"reason":"role.unknown"`.

## 4. stdio (US4)

```php
'stdio_roles' => [get_current_user() => 'developer'],   // write the literal OS user name
'roles' => ['developer' => ['tools' => ['routes.list', 'docs.read'], 'ceiling' => 'internal']],
```

`php artisan mcp:start context-gate` lists both tools. Remove the mapping: empty list, calls record `role.unknown`.

## 5. Per-role limits (US5)

Set `'limits' => ['calls_per_minute' => 2]` on the role. Third call in a minute: `rate.limited`. Set it to `1000`:
`gate:check` fails on `context-gate.roles.<name>.limits.calls_per_minute`.

## 6. Receipts (US6)

Every receipt line has `role`. Change the role's tool list and restart: `rules.classification` changes.

## 7. Old config

Leave a `callers` key in the config: `gate:check` fails on `context-gate.callers` pointing to `roles`.

## 8. Gates

```bash
composer style && composer stan && composer length && composer refs && composer test
```
