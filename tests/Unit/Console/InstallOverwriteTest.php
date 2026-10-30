<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\TestCase;
use Illuminate\Testing\PendingCommand;

class InstallOverwriteTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configDir = sys_get_temp_dir().'/context-gate-overwrite-'.uniqid();
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

    public function test_second_install_without_force_leaves_existing_file_unchanged_and_fails(): void
    {
        file_put_contents($this->configDir.'/context-gate.php', "<?php return ['enabled' => true];\n");

        $command = $this->artisan('gate:install');
        if (! $command instanceof PendingCommand) {
            $this->fail('artisan() did not return a PendingCommand.');
        }
        $command->assertExitCode(1)->execute();

        $this->assertSame("<?php return ['enabled' => true];\n", file_get_contents($this->configDir.'/context-gate.php'));
    }
}
