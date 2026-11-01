<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/** T035, spec 004 US3 scenario 4: a draft that still holds the markers is rejected by gate:check. */
class ClassifyDraftInertTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        // spec 006 FR-011: gate:check reads the token tables, which the test application has only once migrated.
        TokenFactory::migrate();

        Schema::create('classify_draft_users', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('password');
            $table->integer('total');
        });

        $this->root = sys_get_temp_dir().'/context-gate-classify-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        $this->app?->setBasePath($this->root);
        config(['context-gate.sources.eloquent.models' => [ClassifyDraftUser::class]]);
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
    public function gate_check_rejects_the_unedited_draft_as_a_catalog_problem(): void
    {
        Artisan::call('gate:classify');

        $exit = Artisan::call('gate:check');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FAIL app/Gate/classification.php', Artisan::output());
    }
}

class ClassifyDraftUser extends Model
{
    protected $table = 'classify_draft_users';
}
