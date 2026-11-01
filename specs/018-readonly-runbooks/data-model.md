# Data Model: Read-Only Runbooks

## Runbook (host application class, implements `ContextGate\Contracts\Runbook`)

| Member | Type | Rule |
|---|---|---|
| `name()` | string | Matches the registered name in config `runbooks`. Pattern in R8. |
| `description()` | string | Short text; part of the fingerprint (FR-020). |
| `steps()` | `list<RunbookStep>` | 1 to 20 steps (FR-021); names unique (FR-018). Order is the order of the report. |

Config entry: `'runbooks' => ['disk' => App\Runbooks\DiskRunbook::class]`. The key is the name; the value is the class.
Config maps names to classes only; it cannot change steps or limits.

## RunbookStep (`ContextGate\Runbook\RunbookStep`, final class, named constructor arguments)

The step is a value object; its `run` is a closure `Closure(RunbookContext): StepResult`, so one runbook needs no
class per step. Decision recorded here, not in the spec: a class per step would add files without adding a rule.

| Field | Type | Rule |
|---|---|---|
| `name` | string | `^[a-z0-9][a-z0-9_-]{0,63}$`, unique in the runbook |
| `readOnly` | bool | Must be `true` (FR-006). Anything else fails `gate:check`. |
| `timeoutSeconds` | float | Default 2.5; cap 5.0; must be at least 1 (spec 007 review F8) |
| `maxBytes` | int | Default 512; cap 2048; must be at least 1 |
| `run` | Closure | The only code a step runs; receives a `RunbookContext`, returns a `StepResult`. |

## StepStatus (backed enum)

`ok`, `warn`, `fail`, `timeout`, `error`. Exit code mapping (FR-014): `ok` and `warn` pass; `fail`, `timeout`, `error`
fail.

## StepResult

| Field | Type | Rule |
|---|---|---|
| `status` | StepStatus | Returned by the step, or set by the runner to `timeout` or `error` |
| `text` | string | Cut at the step's `maxBytes` (row says so); discarded for `timeout` and `error` |

## RunbookContext (`ContextGate\Runbook\RunbookContext`)

The only public methods (FR-004; test asserts this list):

| Method | Returns | Reads |
|---|---|---|
| `envPresent(string $name)` | bool | whether the environment variable is set; never its value |
| `configPresent(string $key)` | bool | whether the config key is set; never its value |
| `cacheReachable(string $store)` | bool | a read-only ping of the cache store; no key written |
| `queueReachable(string $connection)` | bool | a connection check; no job dispatched |
| `migrationsPending()` | int | count of pending migrations from the migrator's read of the migrations table |
| `failedJobCount()` | int | count of rows in the failed-jobs table |
| `routeExists(string $nameOrUri)` | bool | route lookup by name or URI |

Any failure inside a method (for example a missing migrations table) is caught by the context and returned as
`false` or `0`, or the step throws and the runner maps it to `error`; the message is never returned (FR-009, VII).

## RunbookRun (in memory, one per call; not stored)

| Field | Type | Rule |
|---|---|---|
| `runbook` | string | the name |
| `rows` | list of `{step, status, text}` | in declared order |
| `startedAt` | monotonic float | for the 30 s run budget (R2) |

## Report (`ContextGate\Runbook\Report`)

| Field | Type | Rule |
|---|---|---|
| `rows` | list of `{step, status, text}` | rows that fit in 8 KiB, in order |
| `droppedRows` | int | number of rows not included; the report says so |
| `bytes` | int | serialised size, at most 8192 |

A row is never cut mid-word; a row that does not fit is dropped whole and counted (spec Edge Cases).

## Receipt fields for a runbook call (spec 001 receipt, no new field)

`tool` = `runbook.<name>`, `decision`, `rule_hashes`, `status_counts` (a map from status to count), `runbook` (name).
No row text is recorded (FR-022).

## Fingerprint input (FR-020)

Canonical JSON of: `{"name": ..., "description": ..., "steps": [{"name", "readOnly", "timeoutSeconds", "maxBytes"}, ...]}`
in declared order. Output: sha256 hex.
