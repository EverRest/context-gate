# Contract: store check, store failure and the opt-in jobs

## gate:check store line

```text
PASS context-gate.cache_store: store [redis] (driver redis) answered the probe; eviction policy noeviction
WARN context-gate.cache_store: store [database] (driver database): counters and locks are atomic across hosts, but every call adds writes and row locks to the application database
  fix: point cache_store at a dedicated Redis connection
FAIL context-gate.cache_store: store [array] (host default) (driver array): the counters reset in each process
  fix: set cache_store to a Redis store shared by every worker
FAIL context-gate.cache_store: store [redis] (driver redis) did not answer the probe (RedisException)
  fix: check that the Redis server is reachable from this host
```

Rules:

- Subject is always `context-gate.cache_store`. The text names the store, `(host default)` when `cache_store` is
  null, and the driver.
- One line per run of the check. Status = worst finding. Extra findings (eviction, prefix) are appended with `; `.
- On error only the exception class (anonymous class names cut at the first NUL) appears. Never a message, host,
  port, password or DSN.
- `--json`: the same subject, status, message and fix as the existing check JSON shape.

## Tool call with the store down

```json
{"content":[{"type":"text","text":"Request failed. Reference: r_<id>"}],"isError":true}
```

Receipt: `{"decision":"failed","reason":"store.unavailable",...}`. Operator note on the receipt channel:
`{"reference":"r_<id>","exception":"<class>","file":"<basename>","line":<n>}`.

## HTTP request with the store down

Status `503`, body `{"jsonrpc":"2.0","id":null,"error":{"code":-32000,"message":"Service unavailable."}}`. No
token lookup runs. One `store.unavailable` receipt with caller `unknown`.

## Opt-in environment variables

| Variable | Effect | Default |
|---|---|---|
| `CONTEXT_GATE_CONCURRENCY=1` | runs `tests/Concurrency` | unset: every test skipped, no process, no socket |
| `CONTEXT_GATE_STORE=redis` | workers and sweeps use Redis | `file` |
| `REDIS_HOST`, `REDIS_PORT` | Redis address for the suite | `127.0.0.1`, `6379` |
| `CONTEXT_GATE_REPEAT=<n>` | repetitions of the budget and rate tests | `1` |
| `CONTEXT_GATE_FPM_TEST=1` | runs `tests/EndToEnd/FpmSessionTest.php` (`scripts/fpm-test.sh`) | unset: skipped |

## CI job `concurrency-redis`

- Matrix: Laravel 12 (Testbench `^10`), Laravel 13 (Testbench `^11`); PHP 8.3.
- Service: `redis:7.4`, port 6379, health check `redis-cli ping`, command `--maxmemory-policy noeviction`.
- Steps: install, `CONTEXT_GATE_CONCURRENCY=1 vendor/bin/phpunit --testsuite Concurrency` (file), the same with
  `CONTEXT_GATE_STORE=redis`, then `CONTEXT_GATE_FPM_TEST=1 vendor/bin/phpunit tests/EndToEnd/FpmSessionTest.php`.
- Push: `CONTEXT_GATE_REPEAT=5`; `schedule` (nightly): `CONTEXT_GATE_REPEAT=100` (SC-001).
