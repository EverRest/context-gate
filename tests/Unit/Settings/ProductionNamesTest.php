<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Settings;

use ContextGate\Classification\Catalog;
use ContextGate\Readiness\CheckStatus;
use ContextGate\Readiness\Checks\StoreCheck;
use ContextGate\Readiness\Checks\TransportCheck;
use ContextGate\Readiness\ReadinessContext;
use ContextGate\Settings\EnvironmentProfile;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every production guard follows the profile rule: an environment that may not choose a profile (production, prod,
 * live, any unknown name) is treated as production by all of them, not only by the profile.
 */
class ProductionNamesTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function strictNames(): iterable
    {
        yield 'production' => ['production'];
        yield 'prod' => ['prod'];
        yield 'live' => ['live'];
        yield 'unknown' => ['eu-west-1'];
    }

    /** @return iterable<string, array{string}> */
    public static function configurableNames(): iterable
    {
        yield 'local' => ['local'];
        yield 'development' => ['development'];
        yield 'staging' => ['staging'];
        yield 'testing' => ['testing'];
    }

    #[DataProvider('strictNames')]
    public function test_a_strict_environment_is_forced_for_the_app(string $env): void
    {
        $this->environment($env);

        $this->assertTrue(EnvironmentProfile::appForced());
    }

    #[DataProvider('configurableNames')]
    public function test_a_configurable_environment_is_not_forced_for_the_app(string $env): void
    {
        $this->environment($env);

        $this->assertFalse(EnvironmentProfile::appForced());
    }

    #[DataProvider('strictNames')]
    public function test_preview_refuses_a_strict_environment(string $env): void
    {
        $this->environment($env);

        $this->assertSame(1, Artisan::call('gate:preview', ['tool' => 'probe', '--args' => '{}']));
        $this->assertStringContainsString('gate:preview does not run in production', Artisan::output());
    }

    #[DataProvider('strictNames')]
    public function test_stdio_only_fails_the_transport_check_in_a_strict_environment(string $env): void
    {
        $this->environment($env);

        $result = (new TransportCheck())->run(new ReadinessContext(['http' => ['path' => '']], new Catalog([]), []));

        $this->assertSame(CheckStatus::Fail, $result->status);
    }

    #[DataProvider('strictNames')]
    public function test_a_file_store_fails_in_a_strict_environment(string $env): void
    {
        config(['context-gate.cache_store' => 'gate', 'cache.stores.gate' => ['driver' => 'file', 'path' => sys_get_temp_dir()]]);
        $this->environment($env);

        $result = (new StoreCheck())->run(new ReadinessContext([], new Catalog([]), []));

        $this->assertSame(CheckStatus::Fail, $result->status);
    }

    private function environment(string $name): void
    {
        app()->detectEnvironment(static fn (): string => $name);
    }
}
