<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;

/** T034, spec 004 edge case: no tables give an empty draft with a comment that says why. */
class ClassifyEmptyDatabaseTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/context-gate-classify-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
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
    public function a_configured_model_without_a_table_gives_an_empty_draft_with_a_reason(): void
    {
        config(['context-gate.sources.eloquent.models' => [ClassifyEmptyDatabaseModel::class]]);

        $command = $this->artisan('gate:classify');
        if (! $command instanceof PendingCommand) {
            $this->fail('artisan() did not return a PendingCommand.');
        }
        $command->assertExitCode(0)->execute();

        $draft = (string) file_get_contents($this->root.'/app/Gate/classification.php');
        $this->assertStringContainsString('No tables were found', $draft);
        $this->assertStringContainsString('return [];', $draft);
    }
}

class ClassifyEmptyDatabaseModel extends Model
{
    protected $table = 'classify_missing_table';
}
