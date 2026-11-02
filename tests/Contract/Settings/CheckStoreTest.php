<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/** Spec 008 US1 (T012): gate:check prints the store line; the store check is registered in the gate:check run. */
class CheckStoreTest extends TestCase
{
    public function test_an_array_host_default_fails_gate_check_and_names_the_store(): void
    {
        config(['context-gate.cache_store' => null, 'cache.default' => 'array']);

        $this->assertSame(1, Artisan::call('gate:check'));
        $this->assertMatchesRegularExpression('/FAIL context-gate\.cache_store: store \[array\] \(host default\)/', Artisan::output());
    }

    public function test_a_file_store_outside_production_warns_and_does_not_fail_the_store_line(): void
    {
        app()->detectEnvironment(static fn (): string => 'local');
        config(['context-gate.cache_store' => 'gate', 'cache.stores.gate' => ['driver' => 'file', 'path' => sys_get_temp_dir()]]);

        Artisan::call('gate:check');

        $this->assertMatchesRegularExpression('/WARN context-gate\.cache_store: store \[gate\] \(driver file\)/', Artisan::output());
        $this->assertDoesNotMatchRegularExpression('/FAIL context-gate\.cache_store/', Artisan::output());
    }
}
