# Runbooks

A runbook is a named, ordered list of read-only checks written in your application. Each step reads a few facts
(a variable is set, a cache answers, a migration is pending) and returns a status and a short text. Run it from the
console with `gate:runbook`, or expose it to callers as the tool `runbook.<name>`.

Configuration only maps names to classes; see [configuration.md](configuration.md#runbooks) for the keys.

## Write a runbook

Create a class that implements `ContextGate\Contracts\Runbook`. It returns steps; each step calls only the read methods
of `RunbookContext`.

```php
<?php

namespace App\Runbooks;

use ContextGate\Contracts\Runbook;
use ContextGate\Runbook\RunbookContext;
use ContextGate\Runbook\RunbookStep;
use ContextGate\Runbook\StepResult;
use ContextGate\Runbook\StepStatus;

final class DiskRunbook implements Runbook
{
    public function name(): string
    {
        return 'disk';
    }

    public function description(): string
    {
        return 'Environment, cache and queue checks.';
    }

    public function steps(): array
    {
        return [
            new RunbookStep('env_app_key', readOnly: true, timeoutSeconds: 1.0, maxBytes: 128,
                run: fn (RunbookContext $c): StepResult => $c->envPresent('APP_KEY')
                    ? new StepResult(StepStatus::Ok, 'APP_KEY is set')
                    : new StepResult(StepStatus::Fail, 'APP_KEY is missing')),
            new RunbookStep('queue_failed', readOnly: true, timeoutSeconds: 2.5, maxBytes: 256,
                run: fn (RunbookContext $c): StepResult => $c->failedJobCount() === 0
                    ? new StepResult(StepStatus::Ok, 'no failed jobs')
                    : new StepResult(StepStatus::Warn, 'failed jobs present')),
        ];
    }
}
```

`RunbookStep` takes `name`, `readOnly`, `run`, `timeoutSeconds` (default `2.5`) and `maxBytes` (default `512`).
The constructor throws when the name does not match `^[a-z0-9][a-z0-9_-]{0,63}$`, when the timeout is not above 0 and at
most 5 seconds, or when `maxBytes` is not from 1 to 2048.

`gate:check` and the console also refuse a runbook that:

- has no steps, or more than 20;
- uses a step name twice;
- has a step with `readOnly` set to `false`;
- has a `steps()` method that throws (only the exception class is reported).

Do not put a secret, a config value or a row into a step's text. The context returns only booleans and counts.

## Context methods

`RunbookContext` has seven read methods. None of them writes, dispatches a job or returns a stored value.

| Method | Returns | What it checks |
|---|---|---|
| `envPresent(string $name)` | `bool` | The variable is set (not its value). |
| `configPresent(string $key)` | `bool` | The config key exists. A config error returns `false`. |
| `cacheReachable(string $store)` | `bool` | A read of one key on the store succeeds. A connection error returns `false`. |
| `queueReachable(string $connection)` | `bool` | The queue connection answers a size request. A connection error returns `false`. |
| `migrationsPending()` | `int` | Migration files not yet recorded as run. A database error is not caught, so the step reports `error`. |
| `failedJobCount()` | `int` | Rows in the failed-jobs table (`queue.failed.table`, default `failed_jobs`). A missing table counts 0. |
| `routeExists(string $nameOrUri)` | `bool` | A route with that name, or with that URI (leading slash ignored). |

The step classes (`RunbookStep`, `StepResult`, `StepStatus`, `RunbookContext`) are marked `@internal` in the code, so
this list may change in a later release.

## Status values

A step returns one of five statuses (`StepStatus`):

| Status | Exit code of the run | Meaning |
|---|---|---|
| `ok` | 0 | The check passed. |
| `warn` | 0 | The check passed with a note. |
| `fail` | 1 | The check failed. |
| `timeout` | 1 | The step took longer than its timeout, or no time was left in the run (see below). |
| `error` | 1 | The step threw, or returned something other than a `StepResult`. |

The runner writes fixed texts for the last two cases. A timed-out step shows `Step returned after its time limit; text discarded.`
and its own text is dropped. A step that was not started because the run budget was used up shows `Not run: run limit reached.`
An error shows `Step failed. See the operator note.`; the exception itself is not printed.

## Limits

These come from `Caps` in code. Configuration cannot raise them.

| Limit | Value | Applies to |
|---|---|---|
| Steps per runbook | 1 to 20 | Each runbook. |
| Step timeout | above 0, at most 5.0 s (default 2.5 s) | Each step. |
| Step output | 1 to 2048 bytes (default 512) | Each step's text. Longer text is cut at a UTF-8 boundary and ends with a `[cut]` marker when there is room for it. |
| Report | 8192 bytes of JSON | All rows of one run together. |
| Run | 30 s | All steps together. The budget is checked before each step; a step that starts at or after 30 s is not run. |

A step's timeout is judged after it returns. PHP cannot interrupt a running step, so a step blocked on a hung connection
is not stopped by the run budget; only the connection's own timeout ends it.

When the rows would exceed the report limit, the whole row is dropped and counted. `gate:runbook` prints the count on the
summary line. The tool adds one row, `step: report`, status `warn`, with the same count, and keeps 96 bytes of the limit
free for that row.

## Run it from the console

```bash
php artisan gate:runbook disk
```

The command prints one row per step, then a summary:

```text
runbook: disk (2 steps)
step          status  text
env_app_key   ok      APP_KEY is set
queue_failed  warn    failed jobs present
summary: 1 warn, 1 ok; 0 rows dropped
```

Exit codes:

- `0`: every step is `ok` or `warn`.
- `1`: at least one step is `fail`, `timeout` or `error`, or the output was withheld because a canary value was found
  or the output did not pass the firewall.
- `2`: the name is not registered (the registered names are listed), or the runbook is refused by validation (one
  `refused <name>: <message>` line per problem).

The command runs whether or not the tool is enabled. It runs the rows through the same firewall stages as the tool:
redaction, the canary check and the untrusted marking. It writes no receipt. Each step that threw writes one operator
note to the receipt channel with the exception class, file and line, but not the exception message.

## Expose it to callers (optional)

The tool needs a `tools` entry that names the runbook's tool class and a `callers` entry that lists it:

```php
'runbooks' => [
    'disk' => App\Runbooks\DiskRunbook::class,
],

'tools' => [
    'runbook.disk' => [
        'enabled' => true,
        'class' => ContextGate\Source\RunbookTool::class,
        'fingerprint' => '...', // from gate:fingerprint, see below
    ],
],

'callers' => [
    'alice' => ['tools' => ['runbook.disk']],
],
```

A tool entry without `class` makes the tool registry throw. The part after `runbook.` must be a name under `runbooks`;
a call for an unregistered name is refused.

A caller sees `runbook.disk` only when all three hold: the entry is `enabled`, its fingerprint matches the approved
value, and the caller lists it under `callers`. The runbook's tool takes no arguments.

The caller's ceiling must be `internal`, because the report is internal data. A caller with a `public` ceiling is refused
with a policy denial. Over stdio the ceiling is `firewall.ceiling`; over HTTP it comes from the token.

## Fingerprint

Approve the tool's fingerprint the first time, and again after any change to the fingerprinted fields:

```bash
php artisan gate:fingerprint runbook.disk
php artisan gate:check
```

Paste the printed value into `tools.runbook.disk.fingerprint`. The fingerprint covers the runbook's name, its
description, and each step's name, `readOnly`, timeout and output size, in declared order. Changing any of these fails
`gate:check` for `runbook.disk` until the fingerprint is refreshed.

The fingerprint does not cover the code inside a step's `run` closure. Changing what a step does, without changing one of
the fields above, leaves the fingerprint the same. Review step code the same way you review any other code.

`gate:check` validates every registered runbook and prints one line per runbook. A runbook that fails validation is
shown with its problems and the fix hint.

## What the receipt records

A call to `runbook.<name>` writes the usual receipt (caller, tool, decision, reason, rules). It also writes `status_counts`:
the number of rows per status, for example `{"ok": 1, "warn": 1}`. Only the five status values are counted.

The receipt does not record the step names, the row text, the report size, or any value the steps read.

The row text reaches the caller as untrusted text, after the redaction and canary stages. `gate:runbook` writes no receipt.

## Trust limits

Runbook steps are trusted host code. `readOnly` is a declaration that `gate:check` and the console validate, not
enforcement: a step can write or call out if its own code does. The context a step receives offers only the read
methods above, and that surface is read-only only for the package's own methods. Do not call a write from a step.
