# Contract: `gate:runbook` console output

`php artisan gate:runbook {name}`

Runs the registered runbook whether or not `runbook.<name>` is enabled (FR-013). Writes no receipt (R3).

## Output (stdout)

One header line, then one row per step in declared order, then one summary line:

```text
runbook: disk (3 steps)
step          status  text
env_app_key   ok      APP_KEY is set
cache_redis   warn    store reachable; latency not measured
queue_failed  fail    failed job count is 4
summary: 1 fail, 1 warn, 1 ok; 0 rows dropped
```

Rules:

- Columns are separated by two spaces; `status` is one of `ok`, `warn`, `fail`, `timeout`, `error`.
- A `timeout` row text is `Step returned after its time limit; text discarded.`
- A `error` row text is the fixed text `Step failed. See the operator note.`
- A row cut at the step output limit ends with ` [cut]`.
- When rows were dropped at the 8 KiB report cap, the summary says `N rows dropped`.
- No exception text, no value, no stack trace is printed.
- Text passes the same stages as MCP output (FR-010); a canary hit prints the generic line
  `Output withheld: a canary value was found.` and exits 1.

## Exit codes (FR-014)

| Code | When |
|---|---|
| 0 | every step is `ok` or `warn` |
| 1 | any step is `fail`, `timeout` or `error`; also a canary hit |
| 2 | the name is not registered; stdout lists the registered names only |

Unknown-name output:

```text
runbook not found: disk-x
registered: disk, queue
```

## Stderr

Operator notes for `error` steps only (FR-015): one line per error step with the exception class, file basename and
line. Nothing else goes to stderr.
