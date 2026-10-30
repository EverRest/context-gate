<?php

declare(strict_types=1);

namespace ContextGate\Tests;

use ContextGate\ContextGateServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [ContextGateServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['env'] = 'testing';
    }
}
