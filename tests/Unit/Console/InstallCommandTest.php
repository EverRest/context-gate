<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\TestCase;
use Illuminate\Testing\PendingCommand;

class InstallCommandTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configDir = sys_get_temp_dir().'/context-gate-install-'.uniqid();
        mkdir($this->configDir);
        if ($this->app === null) {
            $this->fail('Application is not booted.');
        }
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
        $command = $this->artisan('gate:install');
        if (! $command instanceof PendingCommand) {
            $this->fail('artisan() did not return a PendingCommand.');
        }
        $command->assertExitCode(0)->execute();

        $config = require $this->configDir.'/context-gate.php';

        $this->assertFalse($config['enabled']);
        $this->assertSame([], $config['tools']);
    }
}
