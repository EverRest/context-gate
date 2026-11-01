# Quickstart: k-Anonymous Aggregates

Prerequisites: specs 012 (filter unit) and 014 (row scopes) implemented; the contract declares its scope or `unscoped`.

1. Declare the tool in `config/context-gate.php`:

   ```php
   'tools' => [
       'entries.count' => [
           'enabled' => true,
           'gate' => 'read-entries',
           'contract' => App\Gate\EntryContract::class,
           'aggregate' => [
               'group_by' => ['nation' => 'value', 'birth_date' => 'year'],
               'filters' => ['event_id' => ['eq', 'in']],
               'k' => 10,
           ],
       ],
   ],
   'callers' => ['token:12' => ['tools' => ['entries.count']]],
   ```

2. `php artisan gate:check` — fails and names the tool and column if a key is a pseudonym, text, relation, raw
   personal column or secret, or if k is outside 5..1000.
3. `php artisan gate:fingerprint entries.count` and paste the value into `fingerprint`.
4. Call it:

   ```json
   {"name":"entries.count","arguments":{"filters":{"event_id":{"eq":42}},"group_by":["nation"],"percent":true}}
   ```

   Groups under k are not named; "other" and the total follow the total rule (contracts/aggregate-tool.md).
5. Optional limits: `aggregates.k` (raise only), `aggregates.max_groups`, `aggregates.max_group_by`,
   `aggregates.queries_per_day` (lower only).
6. Read `docs/privacy.md` "Aggregates and differencing" for the risk that remains.

Verify: run the same call twice — the two responses are byte-identical. After `queries_per_day` calls the next one is
refused and its receipt says `aggregate.budget`.
