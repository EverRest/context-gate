<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures\Runbook;

use ContextGate\Contracts\Runbook;
use ContextGate\Runbook\RunbookContext;
use ContextGate\Runbook\RunbookStep;
use ContextGate\Runbook\StepResult;
use ContextGate\Runbook\StepStatus;

/** Fake runbook for tests (spec 018 US3): checks seeded env, config, cache and database values by name only. */
final class SecretsRunbook implements Runbook
{
    public function name(): string
    {
        return 'secrets';
    }

    public function description(): string
    {
        return 'Checks seeded values by name.';
    }

    public function steps(): array
    {
        return [
            new RunbookStep(name: 'env_app_key', readOnly: true, run: fn (RunbookContext $c): StepResult => $c->envPresent('APP_KEY')
                ? new StepResult(StepStatus::Ok, 'APP_KEY is set')
                : new StepResult(StepStatus::Fail, 'APP_KEY is not set')),
            new RunbookStep(name: 'config_value', readOnly: true, run: fn (RunbookContext $c): StepResult => new StepResult(StepStatus::Ok, $c->configPresent('services.seeded.token') ? 'config key is set' : 'config key is not set')),
            new RunbookStep(name: 'cache_store', readOnly: true, run: fn (RunbookContext $c): StepResult => new StepResult(StepStatus::Ok, $c->cacheReachable('array') ? 'cache reachable' : 'cache not reachable')),
            new RunbookStep(name: 'failed_jobs', readOnly: true, run: fn (RunbookContext $c): StepResult => new StepResult(StepStatus::Ok, 'failed job count is '.$c->failedJobCount())),
        ];
    }
}
