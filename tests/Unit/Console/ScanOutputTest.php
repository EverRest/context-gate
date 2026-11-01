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
    public function json_prints_the_report_to_stdout_and_writes_no_file(): void
    {
        $this->assertSame(0, Artisan::call('gate:scan', ['--json' => true]));

        $json = Artisan::output();
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data['routes']);
        $this->assertSame('scan_output_users', $data['models'][0]['table']);
        $this->assertStringNotContainsString('FAKE_FIXTURE_ONLY', $json);
        $this->assertFileDoesNotExist($this->root.'/storage/context-gate/scan.json');
    }

    #[Test]
    public function output_writes_the_json_file_with_0755_directories_and_no_row_value(): void
    {
        $file = $this->root.'/out/nested/scan.json';

        $this->assertSame(0, Artisan::call('gate:scan', ['--output' => $file]));

        $this->assertFileExists($file);
        $this->assertSame(0755, fileperms(dirname($file)) & 0777);
        $json = (string) file_get_contents($file);
        $this->assertSame('scan_output_users', json_decode($json, true, 512, JSON_THROW_ON_ERROR)['models'][0]['table']);
        $this->assertStringNotContainsString('FAKE_FIXTURE_ONLY', $json);
        @unlink($file);
        @rmdir(dirname($file));
        @rmdir(dirname($file, 2));
    }

    #[Test]
    public function no_models_says_how_to_set_them(): void
    {
        config(['context-gate.sources.eloquent.models' => []]);

        Artisan::call('gate:scan');

        $this->assertStringContainsString('Models: 0 (set sources.eloquent.models to scan models)', Artisan::output());
    }
}

class ScanOutputUser extends Model
{
    protected $table = 'scan_output_users';
}
