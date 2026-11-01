# Research: Durable Receipt Sinks and Operator Metrics

Code read at HEAD `d744441`. Paths are relative to the repository root.

## State found in the code

| Item | Where | State |
|---|---|---|
| Receipt writer | `src/Server/Receipt.php` | One static `write()`; builds the record; `Log::channel(config('context-gate.channel'))->info($line)`. A logger exception propagates. |
| Receipt id | `Receipt::write` | `'r_'.bin2hex(random_bytes(4))`: 32 random bits. Nine test files assert `r_[0-9a-f]{8}` (`tests/Unit/Server/ReceiptTest.php`, `tests/Contract/{CanaryBlock,PseudonymKeyFailCall,ReviewFixesRuntime,ReviewRound2,HandlerFailure,Projection,TransformFailure,CanaryPlainField}Test.php`). |
| Receipt fields | same | `id, at, caller, tool, decision, reason, rules` + optional `dropped, truncated, flags, refusal_step, source, simulated, status_counts`. No `transport`. |
| Writers | `ResponseBuilder::receipt`, `GateCallTool::visibleTool`, `RefusalLedger`, `GateAuthRefusal` path, `Simulator`, runbook path | 6 direct `Receipt::write` call sites; all go through the one method. |
| Operator notes | `src/Server/Failure.php`, `src/Settings/ConfigAudit.php` | Own JSON shapes on the same channel. |
| Channel default | `ContextGateServiceProvider::register` | `context-gate` channel → stderr when the host did not define it. |
| OS user | `app('context-gate.caller')` | posix user name or `unknown` (used by config rollback audit). |
| gate:audit | `src/Console/AuditCommand.php` | Lists tools only (spec 006). `--json`. |
| Migrations | `database/migrations/` | Loaded by `loadMigrationsFrom` and publishable under `context-gate-migrations`. |
| Response on receipt failure | `GateCallTool::handle` | A failing logger throws inside `crashed()` too: the exception escapes `handle()`. Principle IV holds by accident (no response), but the framework may report the message. |

## R1. Sink abstraction (FR-001–FR-006)

**Decision**: `src/Receipt/` namespace.

- `Sink` interface: `name(): string`, `write(array $record): void` (throws on failure). Two implementations:
  `LogSink` (channel, format `line` or `structured`) and `DatabaseSink` (connection, table, retention). The interface
  is justified by two implementations and by tests that need a failing sink.
- `SinkSet::deliver(array $record): void`:
  1. Write to every required sink, in configured order. Collect failures.
  2. If any required sink failed: try a `sink.failed` note on the other required sinks (errors ignored), then throw
     `ReceiptNotWritten` (fixed message, original as previous).
  3. Write to each best-effort sink, timing each. A throw or an elapsed time above the timeout is a failure: a
     `sink.failed` note (receipt id, sink name, failure class or `SinkTimeout`) goes to every required sink.
- `Receipt::write()` keeps its signature, builds the record, calls `app(SinkSet::class)->deliver($record)`, returns
  the id. No caller changes.
- `ReceiptNotWritten` is caught first in `GateCallTool::handle`: the model gets the fixed text `Request failed.` with
  no reference (no receipt exists to refer to). The HTTP middleware path answers 503 with a generic JSON-RPC error.

**Note record** (FR-006): a record with `caller: "system"`, `tool: null`, `decision: "note"`,
`reason: "sink.failed"`, and `note: {receipt, sink, exception}`. New `ReasonCode::SinkFailed` with decision `note`.
Being a record, it reaches the log line, the structured line and the table with no special path, and the
"best-effort sink failures" metric is a plain count.

**Timeout ceiling** (spec edge case "never delays beyond that timeout"): PHP writes synchronously; a write cannot be
interrupted. Bounds used: the receipt database connection gets `PDO::ATTR_TIMEOUT` = the timeout and, on MySQL,
`innodb_lock_wait_timeout = 1` for its session; a log sink has no bound the package can set. A write that finishes
after the timeout is kept and still counted as failed. This is the known ceiling; see Complexity Tracking.

**Alternatives rejected**: queueing best-effort writes (a queue is a host dependency and a lost receipt on worker
death); `register_shutdown_function` / `terminating` (stdio runs many calls in one process, so "after the response"
does not exist there).

## R2. Configuration (FR-002, FR-003, FR-008)

```php
'receipts' => [
    // null: one required log sink on `channel`, line format (FR-003).
    'sinks' => null,
    // e.g. ['log' => ['kind' => 'log', 'mode' => 'required', 'format' => 'structured'],
    //       'db'  => ['kind' => 'database', 'mode' => 'best_effort']]
    'retention_days' => 30,        // cap Caps::RECEIPT_RETENTION_DAYS = 180
    'timeout_seconds' => 1.0,      // best-effort; cap Caps::SINK_TIMEOUT = 1.0
    'connection' => null,          // database sink source connection; null = database.default
    'table' => 'gate_receipts',
],
```

- `SettingsSchema` and `SettingsValidator` (spec 007) gain these keys: `kind` ∈ {log, database}; `mode` ∈ {required,
  best_effort}; `format` ∈ {line, structured} (log only); `channel` (log only, default `context-gate.channel`); at
  least one required sink; at most one database sink; numeric values ≤ caps. Invalid → `config.invalid` for every
  call (existing `ActiveSettings` path) and FAIL in `gate:check` (existing `SettingsCheck`).
- When the sink settings are invalid, `SinkSet` falls back to the default required log sink, so the `config.invalid`
  receipts are still written (principle IV).
- New caps in `src/Pipeline/Caps.php`: `RECEIPT_RETENTION_DAYS = 180`, `SINK_TIMEOUT = 1.0`, `AUDIT_WINDOW_DAYS = 31`,
  `AUDIT_LIMIT = 1000`; default limit 100 is a constant in the query class.
- Spec 009 order: the sink settings are read through `EffectiveSettings` (today) or 009's accessor (later); one
  method, `SinkSet::fromSettings()`, is the only reader.

## R3. Table schema and the separate connection (FR-008, FR-009, FR-019)

Migration `database/migrations/2026_11_01_000000_create_gate_receipts_table.php` (loaded and publishable like the
token tables):

| Column | Type | Notes |
|---|---|---|
| `id` | string(32), primary | receipt id `r_` + 24 hex |
| `at` | datetime | write time, UTC |
| `expires_at` | datetime | `at` + retention days |
| `caller` | string(191) | |
| `tool` | string(191), nullable | |
| `decision` | string(16) | |
| `reason` | string(64), nullable | |
| `transport` | string(8), nullable | |
| `source` | string(32), nullable | preview marker |
| `simulated` | boolean, default false | |
| `truncated` | boolean, nullable | |
| `record` | json | the full receipt record (metadata only, same fields as the log) |

Indexes: `(caller, at)`, `(reason, at)`, `(at)`, `(expires_at)`.

Connection: the provider defines `database.connections.context-gate-receipts` as a copy of the source connection
(`receipts.connection` or `database.default`) when the host has not defined it. Laravel opens a separate PDO per
connection name, so a host transaction on the default connection never contains the insert (FR-019). Test note: an
in-memory SQLite copy is a different database, so the FR-019 test uses a temporary SQLite file shared by both names.

Expiry: computed from the write time; `expires_at ≤ at + 180 days` always (a backwards clock cannot extend it, since
both come from the same `now()`).

## R4. Pruning

**Decision**: `gate:receipts:prune` deletes rows with `expires_at <= now()` in chunks of 1000 by primary key, prints
`Pruned <n> receipts.` and nothing else. The host schedules it (spec assumption); the docs show
`Schedule::command('gate:receipts:prune')->daily()`. Queries always add `expires_at > now()` (FR-009 hides unpruned
rows).

**Alternatives rejected**: Eloquent `MassPrunable` (needs a model class and `model:prune` registration for one
delete query); package self-scheduling (spec: the host schedules).

## R5. Receipt id (FR-017)

**Decision**: `'r_'.bin2hex(random_bytes(12))`: 96 random bits, 26 characters. Collision probability over the
180-day cap at 10⁶ receipts per day is about 2⁻³⁶; a collision is a primary-key failure, which the sink set treats
as a sink failure. The nine tests that assert `{8}` move to `{24}`.

## R6. Structured format for Loki (FR-010, FR-011)

**Decision**: `format: structured` writes one JSON object with every documented key present (null when absent):

`v` (1), `type` (`receipt` | `note`), `id`, `at`, `caller`, `tool`, `decision`, `reason`, `transport`, `source`,
`simulated`, `truncated`, `dropped`, `flags`, `refusal_step`, `status_counts`, `rules`, `note`.

- Label fields (bounded): `decision`, `reason`, `transport`, `tool`, `source`. Never `caller` or `id`.
  `tool` is bounded because a name outside the registry is never written (spec 001 F2).
- A new field needs `v` + 1 (FR-010). A test asserts the key set equals the documented list for every reason code.
- `transport` is new on every receipt (`stdio`, `http`, `cli`), bound in the container at server boot; console
  commands default to `cli`. This is a receipt format addition (spec 001); see the report.
- Over stdio the channel is stderr (existing default); a test asserts stdout is empty.
- `Failure` and `ConfigAudit` lines keep their own shapes; the Loki pipeline selects receipts with `| json | v="1"`.

## R7. gate:audit query mode (FR-012–FR-015)

```text
php artisan gate:audit --receipts [--caller=] [--tool=] [--reason=] [--decision=]
                       [--since=] [--until=] [--limit=100] [--json]
php artisan gate:audit --summary [--since=] [--until=] [--json]
```

- `--since`/`--until`: ISO 8601 or relative (`-24 hours`); default the last 24 hours. Window > 31 days → exit 1,
  "window is above the cap of 31 days". `--limit` > 1000 → exit 1 naming the cap.
- Database sink not configured → exit 1 "queries need the database sink".
- Output: newest first; columns `id at caller tool decision reason`; `--json` prints only a JSON array (or the summary
  object). Rows past `expires_at` never appear.
- One audit line per run on the receipt channel: `{"event":"receipts.query","actor":<OS user>,"at":…,"filters":[keys]}`
  (filter keys, never values).
- The tool listing (spec 006) stays the default with no flag.

## R8. Metrics (FR-016)

**Decision**: one class `src/Receipt/Metrics.php` holds each metric as a named filter over receipt fields; the
summary query and the documentation table are generated from it, and SC-005's test applies it to a seeded set.
Every metric excludes `source IS NOT NULL` (preview) and `simulated = true`.

| Metric | Filter |
|---|---|
| `calls_by_decision.<d>` | `type=receipt`, group by `decision` |
| `refusals_by_reason.<r>` | `decision ∈ {refused, denied, hidden, blocked}`, group by `reason` |
| `canary_hits` | `reason = canary.hit` |
| `budget_exhausted_session` | `reason = budget.session` |
| `budget_exhausted_daily` | `reason = budget.daily` |
| `rate_limited` | `reason = rate.limited` |
| `throttled_clients` | `reason = http.throttled` |
| `handler_failures` | `reason = handler.failed` |
| `transform_failures` | `reason = transform.failed` |
| `pin_mismatches` | `reason = tool.pin_mismatch` |
| `truncated` | `truncated = true` |
| `sink_failures` | `reason = sink.failed` |

## R9. gate:check (FR-018)

`src/Readiness/Checks/ReceiptSinkCheck.php`: FAIL when the database sink is configured and the table is missing
(names the table); WARN when expired rows exist (count only); WARN when a required log sink's channel uses the `null`
driver or a monolog `NullHandler`. Missing required sink and caps are already FAILs through `SettingsCheck` (R2).

## Cross-spec

- 015: `rules.classification` already holds the classification hash; 015 T026 makes it the merged catalog. No 016
  change.
- 017: adds a `kind` field (tool, resource, prompt). It is a structured-format field addition: `v` 2, and a nullable
  `kind` column in a 017 migration. This plan keeps `type` (receipt vs note) distinct from 017's `kind`.
- 008: receipts never use the cache store; `store.unavailable` is one more bounded `reason` value.
