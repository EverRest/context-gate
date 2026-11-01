# Quickstart: Durable Receipt Sinks and Operator Metrics

In a host application with the package installed.

## 1. Default: one required log sink (unchanged)

```sh
php artisan gate:check      # no receipts lines beyond PASS
```

## 2. Add the database sink

```sh
php artisan migrate         # creates gate_receipts (package migration)
```

`config/context-gate.php`:

```php
'receipts' => [
    'sinks' => [
        'log' => ['kind' => 'log', 'mode' => 'required', 'format' => 'structured'],
        'db'  => ['kind' => 'database', 'mode' => 'best_effort'],
    ],
    'retention_days' => 30,
],
```

```sh
php artisan gate:check
php artisan gate:preview <tool> --args='{}'     # writes a receipt (source marked; excluded from metrics)
```

## 3. Query and summarise

```sh
php artisan gate:audit --receipts --since='-1 hour'
php artisan gate:audit --receipts --caller=token:12 --since='-1 day' --json | jq length
php artisan gate:audit --summary --since='-7 days'
php artisan gate:audit --receipts --since='-40 days'      # exit 1: above the 31 day cap
```

## 4. Prune on a schedule

`routes/console.php`:

```php
Schedule::command('gate:receipts:prune')->daily();
```

```sh
php artisan gate:receipts:prune     # Pruned <n> receipts.
```

## 5. Principle IV with a failing required sink

Set `db.mode` to `required`, stop the database, call a tool: the client gets `Request failed.` and no data. Set it
back to `best_effort`: the call is served and the log holds a `sink.failed` note naming `db`.

## 6. Loki labels

Promtail / Alloy stage: `json` then `labels: decision, reason, transport, tool, source`. Never label `caller` or
`id`. Select receipts with `v="1"`.

## Package tests

```sh
vendor/bin/phpunit --filter 'Sink|Receipt|AuditQuery|Prune|Metrics'
composer style && composer stan && composer length && composer test
```
