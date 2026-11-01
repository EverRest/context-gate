<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\Fixtures\SeededPii;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * T011, spec 004 FR-018 and T054: none of the four commands prints a seeded value to stdout or stderr. Artisan::output()
 * holds both streams, and the receipt lines written to the log channel are checked too.
 */
class NoValueOutputTest extends TestCase
{
    use GateCallHarness;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('no_value_users', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
        });
        DB::table('no_value_users')->insert(['id' => 1, 'email' => SeededPii::EMAIL]);

        $this->root = sys_get_temp_dir().'/context-gate-novalue-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        $this->app?->setBasePath($this->root);
        config(['context-gate.sources.eloquent.models' => [NoValueUser::class]]);
    }

    protected function tearDown(): void
    {
        @unlink($this->root.'/app/Gate/classification.php');
        @rmdir($this->root.'/app/Gate');
        @rmdir($this->root.'/app');
        @rmdir($this->root.'/storage/context-gate');
        @rmdir($this->root.'/storage');
        @rmdir($this->root);

        parent::tearDown();
    }

    #[Test]
    public function gate_classify_prints_no_seeded_value(): void
    {
        Artisan::call('gate:classify');

        $this->assertStringNotContainsString(SeededPii::EMAIL, Artisan::output());
    }

    #[Test]
    public function gate_scan_prints_no_seeded_value(): void
    {
        Artisan::call('gate:scan');

        $this->assertStringNotContainsString(SeededPii::EMAIL, Artisan::output());
    }

    #[Test]
    public function gate_canary_prints_no_seeded_value(): void
    {
        config(['context-gate.sources.openapi.allowlist' => [SeededPii::EMAIL]]);

        Artisan::call('gate:canary');

        $this->assertStringNotContainsString(SeededPii::EMAIL, Artisan::output());
    }

    #[Test]
    public function gate_preview_prints_and_logs_no_seeded_value(): void
    {
        $this->recordLines();
        $this->configureTool(ProbeContract::class, ['customers' => ['id' => 'public', 'status' => 'public', 'email' => 'personal', 'note' => 'personal']]);
        ProbeTool::$output = (string) json_encode([['id' => 42, 'status' => 'paid', 'email' => SeededPii::EMAIL, 'note' => SeededPii::EMAIL]]);

        Artisan::call('gate:preview', ['tool' => 'probe', '--args' => '{"text":"x"}']);

        $this->assertStringNotContainsString(SeededPii::EMAIL, Artisan::output());
        $this->assertNotEmpty($this->lines);
        $this->assertStringNotContainsString(SeededPii::EMAIL, implode("\n", $this->lines));
    }
}

class NoValueUser extends Model
{
    protected $table = 'no_value_users';
}
