# Quickstart: Redis as the Tested Cache Store

## 1. Default suite opens no connection

```sh
composer test
```

The `Concurrency` suite and `FpmSessionTest` report as skipped.

## 2. File-store concurrency (one host)

```sh
CONTEXT_GATE_CONCURRENCY=1 vendor/bin/phpunit --testsuite Concurrency
```

## 3. Redis concurrency

```sh
docker run --rm -d --name cg-redis -p 6379:6379 redis:7.4 --maxmemory-policy noeviction
CONTEXT_GATE_CONCURRENCY=1 CONTEXT_GATE_STORE=redis vendor/bin/phpunit --testsuite Concurrency
CONTEXT_GATE_CONCURRENCY=1 CONTEXT_GATE_STORE=redis CONTEXT_GATE_REPEAT=100 vendor/bin/phpunit --filter BudgetProcessTest
```

Expect: all pass; `KeyTtlSweepTest` reports 0 keys without expiry.

## 4. PHP-FPM session check

Needs `php-fpm` and `nginx` on the machine (CI has both):

```sh
CONTEXT_GATE_FPM_TEST=1 vendor/bin/phpunit tests/EndToEnd/FpmSessionTest.php
```

## 5. gate:check against each store (in a host app)

```sh
php artisan gate:check                                    # with cache_store => null and CACHE_STORE=array: FAIL
CACHE_STORE=file APP_ENV=local php artisan gate:check     # WARN
CACHE_STORE=database php artisan gate:check               # WARN
CACHE_STORE=redis php artisan gate:check                  # PASS when Redis answers
```

## 6. Store down

```sh
docker stop cg-redis
php artisan gate:check      # FAIL context-gate.cache_store ... (class only)
```

A tool call now returns `Request failed. Reference: r_…` and the receipt holds `store.unavailable`. Start Redis
again; the next call is served with no restart.

## 7. Key listing

```sh
docker exec cg-redis redis-cli --scan --pattern '*context-gate:*' | head
```

Every key starts with the cache prefix, then `context-gate:`.

```sh
docker rm -f cg-redis
```
