# Concurrency tests

The multi-process tests check that the budgets, rate limits, refusal counters and pseudonyms hold when several PHP
processes share one cache store. They spawn real worker processes and check exact outcomes: no limit exceeded, the
limit reached exactly, one pseudonym per record.

They are opt-in, so `composer test` never starts a worker or opens a connection:

```sh
CONTEXT_GATE_CONCURRENCY=1 vendor/bin/phpunit --testsuite Concurrency          # file store, one host
CONTEXT_GATE_CONCURRENCY=1 CONTEXT_GATE_STORE=redis vendor/bin/phpunit --testsuite Concurrency
```

Without `CONTEXT_GATE_CONCURRENCY=1` every test is skipped in `setUp`, before any worker starts. `OptInGateTest` checks
that in the default run.

## Redis

`CONTEXT_GATE_STORE=redis` runs the same workers against Redis at `REDIS_HOST` and `REDIS_PORT` (default
`127.0.0.1:6379`), database 15. Each test run uses its own key prefix (`cg-<random>:`), and removes the keys of that
prefix afterwards. Redis access uses the native `redis` extension (phpredis); the package adds no dependency. Without
the extension the Redis tests are skipped.

```sh
docker run --rm -d --name cg-redis -p 6379:6379 redis:7.4 --maxmemory-policy noeviction
CONTEXT_GATE_CONCURRENCY=1 CONTEXT_GATE_STORE=redis vendor/bin/phpunit --testsuite Concurrency
CONTEXT_GATE_CONCURRENCY=1 CONTEXT_GATE_STORE=redis CONTEXT_GATE_REPEAT=100 vendor/bin/phpunit --filter BudgetProcessTest
docker rm -f cg-redis
```

`CONTEXT_GATE_REPEAT=<n>` repeats the budget and refusal tests, each round with its own session, caller or bearer.
CI runs 5 repeats on push and 100 on the nightly schedule (`.github/workflows/ci.yml`, job `concurrency-redis`).

## What they cover

- `BudgetProcessTest`: eight processes reserve bytes against one session limit and one day limit. The total served
  never exceeds the limit and fills it exactly. Eight processes share one per-minute call limit, which admits exactly
  the limit.
- `RefusalLedgerProcessTest`: on Redis, the refusal count of 160 refusals from eight processes is exactly 160, and a
  limit inside that range writes exactly one `http.throttled` receipt across all workers. On the file store the count
  is bounded only: a file store cannot increment atomically across processes.
- `PseudonymProcessTest`: eight processes mint pseudonyms for overlapping record batches in day, week and persistent
  session scopes. Every worker gets the same pseudonym for the same record, the reverse map holds one entry per
  record, and every issued pseudonym resolves in a fresh process. A persisted session pseudonym also resolves, and
  repeats, in a later process; this is the PHP-FPM case without HTTP.
- `KeyTtlSweepTest` (Redis): after every kind of worker, each key of the run has a TTL, and none is above its family
  maximum. A key that matches no family fails the test (`KeyFamilies.php`, a test-only map).
- `BudgetVanishTest` (Redis): a day counter that vanishes between add and increment is recreated with its TTL.

## Key namespace and TTLs

Every key is `context-gate:<family>:<parts>`, and every lock is `context-gate:lock:<family>:<parts>`. These are the
maximum times to live:

| Key family | Maximum time to live |
|---|---|
| per-minute rate limit (and its `:timer`) | 60 seconds |
| session byte budget | 3600 seconds |
| day byte budget | until the next 00:00 UTC (at most 86400 seconds) |
| refusal counters and markers | 60 seconds |
| day pseudonym state | 86400 seconds |
| week pseudonym state | 604800 seconds |
| persistent-session pseudonym state | sliding 3600 seconds, never past creation plus 604800 seconds |
| locks | 5 seconds |

Memory is bounded by the callers, sessions and days in use within these times. Pseudonym state keeps at most 10000
records per scope map (the key rotates beyond it, and older pseudonyms stop resolving), and the process keeps at most
100 session scopes in memory on stdio.

## Known limits

- A counter is atomic and a lock is exclusive on each shard; a Redis cluster keeps a counter and its lock on different
  shards, and no transaction spans them. Budgets only need each one atomic.
- Day counters use the UTC date of the worker that writes them. A call at midnight may count against either day,
  within the per-minute limit.
- The refusal counter takes its lock without waiting, so under contention it counts without the lock and can lose a
  count on a store whose increment is not atomic. On Redis the increment is atomic. A counter that expires between its
  add and its increment can stay without a TTL; the ledger does not repair that (see the `ponytail:` note in
  `src/Http/RefusalLedger.php`).
- Budgets and pseudonym state take their lock with a wait of 3 seconds. A lock that is not acquired in time refuses the
  call with `store.unavailable`.
