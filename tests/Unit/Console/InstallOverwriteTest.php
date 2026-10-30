<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\TestCase;

class InstallOverwriteTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configDir = sys_get_temp_dir().'/context-gate-overwrite-'.uniqid();
        mkdir($this->configDir);
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

        $this->artisan('gate:install')->assertExitCode(1);

        $this->assertSame("<?php return ['enabled' => true];\n", file_get_contents($this->configDir.'/context-gate.php'));
    }
}
