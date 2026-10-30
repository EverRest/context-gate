<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configDir = sys_get_temp_dir().'/context-gate-install-'.uniqid();
        mkdir($this->configDir);
        $this->app->useConfigPath($this->configDir);
    }

    protected function tearDown(): void
    {
        @unlink($this->configDir.'/context-gate.php');
        @rmdir($this->configDir);

        parent::tearDown();
    }

    public function test_install_publishes_configuration_with_every_tool_disabled(): void
    {
        $this->artisan('gate:install')->assertExitCode(0);

        $config = require $this->configDir.'/context-gate.php';

        $this->assertFalse($config['enabled']);
        $this->assertSame([], $config['tools']);
    }
}
