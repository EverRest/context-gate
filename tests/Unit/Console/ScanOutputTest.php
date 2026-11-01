<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/** T054, spec 004 FR-018 and T011: gate:scan prints names and classes, never a row value; --json writes scan.json. */
class ScanOutputTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('scan_output_users', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('api_token');
        });
        DB::table('scan_output_users')->insert(['id' => 1, 'email' => 'FAKE_FIXTURE_ONLY@example.invalid', 'api_token' => 'FAKE_FIXTURE_ONLY_TOKEN']);

        Route::get('/scan-fixture', static fn (): string => 'ok');

        $this->root = sys_get_temp_dir().'/context-gate-scan-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        $this->app?->setBasePath($this->root);
        config(['context-gate.sources.eloquent.models' => [ScanOutputUser::class]]);
    }

    protected function tearDown(): void
    {
        @unlink($this->root.'/storage/context-gate/scan.json');
        @rmdir($this->root.'/storage/context-gate');
        @rmdir($this->root.'/storage');
        @rmdir($this->root);

        parent::tearDown();
    }

    #[Test]
    public function the_report_lists_routes_models_columns_and_suggestions_but_no_row_value(): void
    {
        $this->assertSame(0, Artisan::call('gate:scan'));

        $output = Artisan::output();
        $this->assertStringContainsString('Routes: ', $output);
        $this->assertStringContainsString(ScanOutputUser::class, $output);
        $this->assertStringContainsString('scan_output_users', $output);
        $this->assertStringContainsString('email', $output);
        $this->assertStringContainsString('personal (suggested)', $output);
        $this->assertStringNotContainsString('FAKE_FIXTURE_ONLY', $output);
    }

    #[Test]
    public function json_writes_scan_json_without_any_row_value(): void
    {
        $this->assertSame(0, Artisan::call('gate:scan', ['--json' => true]));

        $file = $this->root.'/storage/context-gate/scan.json';
        $this->assertFileExists($file);

        $json = (string) file_get_contents($file);
        $this->assertStringContainsString('scan_output_users', $json);
        $this->assertStringNotContainsString('FAKE_FIXTURE_ONLY', $json);
    }
}

class ScanOutputUser extends Model
{
    protected $table = 'scan_output_users';
}
