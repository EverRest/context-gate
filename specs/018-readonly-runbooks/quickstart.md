# Quickstart: Read-Only Runbooks

This shows how a developer writes, registers, checks and runs one runbook. It is the target of the docs task after the
feature ships (the docs are not changed by this plan).

## 1. Write the runbook

Create a class in the host application. It returns steps; each step calls only the read checks of the context.

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

Rules that `gate:check` enforces: `readOnly` must be `true`; 1 to 20 steps; unique step names matching
`^[a-z0-9][a-z0-9_-]{0,63}$`; timeout at most 5 seconds; output at most 2048 bytes.

Do not put a secret, a config value or a row into a step's text. The context returns only booleans and counts. A step
that calls a write method in its own host code is outside the package's guarantee; do not do it.

## 2. Register it

In `config/context-gate.php`:

```php
'runbooks' => [
    'disk' => App\Runbooks\DiskRunbook::class,
],
```

## 3. Run it from the console

```bash
php artisan gate:runbook disk
```

The command prints one row per step and exits 0 (all ok or warn), 1 (a fail, timeout or error, or a canary hit) or 2
(name not registered). It works even when the MCP tool is disabled.

## 4. Expose it to callers (optional)

Enable the tool and list it for a caller:

```php
'tools' => [
    'runbook.disk' => [
        'enabled' => true,
        'class' => ContextGate\Source\RunbookTool::class,
    ],
],
```

Then approve its fingerprint (the first time, and after every change to a step):

```bash
php artisan gate:fingerprint runbook.disk
php artisan gate:check
```

Callers that list `runbook.disk` see it in `tools/list`; others get the same response as an unknown tool.

## 5. After a change

Changing a step's name, `readOnly`, timeout or output size, or adding or removing a step, changes the fingerprint.
`gate:check` then fails for `runbook.disk` until `gate:fingerprint` is run again.
