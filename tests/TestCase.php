<?php

declare(strict_types=1);

namespace ContextGate\Tests;

use ContextGate\ContextGateServiceProvider;
use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Server\ContractGuard;
use ContextGate\Server\GateServer;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [ContextGateServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app?->instance('config', new SettingsForgettingConfig($this->app->make('config')->all()));

        // The HTTP boot turns this on for the process; a test must not inherit it.
        Pseudonymizer::usePersistentSessions(false);
        ContractGuard::clear();
        GateServer::forgetDiagnostics();
    }

    protected function defineEnvironment($app): void
    {
        $app['env'] = 'testing';
        // The profile rule reads config('app.env'), which otherwise falls back to production when no .env exists.
        $app['config']->set('app.env', 'testing');
        // The Budget counters use the default cache store; the test application has no cache table.
        $app['config']->set('cache.default', 'array');
        // The gate is off by default; the call-path tests need it on, and EnabledFlagTest covers both values.
        $app['config']->set('context-gate.enabled', true);
    }
}
