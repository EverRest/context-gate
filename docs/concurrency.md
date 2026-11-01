# Concurrency tests

The multi-process tests check that the budgets and pseudonyms hold when several PHP processes share one cache store.
They spawn real worker processes against a file cache store in a temporary directory, which is removed afterwards.

They are opt-in, so `composer test` never starts a worker:

```sh
CONTEXT_GATE_CONCURRENCY=1 vendor/bin/phpunit --group concurrency
```

Without the variable the tests are skipped.

What they cover:

- `BudgetProcessTest`: eight processes reserve bytes against one session limit, and the total served never exceeds it
  (and fills it exactly). Eight processes also share one per-minute call limit.
- `PseudonymProcessTest`: eight processes mint a pseudonym for one value in one scope. All get the same pseudonym, and
  the reverse map holds one entry. A persisted session pseudonym also resolves, and repeats, in a later process. This
  is the PHP-FPM case without HTTP.

The file cache store has no atomic increment, so Budget takes a per-counter lock on any store that provides one. On
stores whose increment is already atomic the lock only adds a round trip per reservation.
