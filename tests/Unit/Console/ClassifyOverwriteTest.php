<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\TestCase;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;

/** T033, spec 004 FR-014: gate:classify never overwrites an existing catalog without --force. */
class ClassifyOverwriteTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/context-gate-classify-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/app/Gate', 0777, true);
        $this->app?->setBasePath($this->root);
    }

    protected function tearDown(): void
    {
        @unlink($this->root.'/app/Gate/classification.php');
        @rmdir($this->root.'/app/Gate');
        @rmdir($this->root.'/app');
        @rmdir($this->root);

        parent::tearDown();
    }

    #[Test]
    public function an_existing_catalog_is_unchanged_without_force_and_the_command_exits_1(): void
    {
        $existing = "<?php return ['users' => ['email' => 'personal']];\n";
        file_put_contents($this->root.'/app/Gate/classification.php', $existing);

        $command = $this->artisan('gate:classify');
        if (! $command instanceof PendingCommand) {
            $this->fail('artisan() did not return a PendingCommand.');
        }
        $command->assertExitCode(1)->execute();

        $this->assertSame($existing, file_get_contents($this->root.'/app/Gate/classification.php'));
    }
}
