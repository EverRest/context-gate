<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/** T051, spec 004 FR-012 and SC-003: gate:scan enables no tool and changes no configuration file. */
class ScanInertTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('scan_inert_users', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
        });

        $this->root = sys_get_temp_dir().'/context-gate-scan-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        $this->app?->setBasePath($this->root);
        config(['context-gate.sources.eloquent.models' => [ScanInertUser::class]]);
    }

    protected function tearDown(): void
    {
        @rmdir($this->root);

        parent::tearDown();
    }

    #[Test]
    public function scan_changes_no_configuration_file_and_enables_no_tool(): void
    {
        $configDir = dirname(__DIR__, 3).'/config';
        $before = $this->hashes($configDir);
        $toolsBefore = config('context-gate.tools');

        $this->assertSame(0, Artisan::call('gate:scan'));

        $this->assertSame($before, $this->hashes($configDir));
        $this->assertSame($toolsBefore, config('context-gate.tools'));
    }

    #[Test]
    public function scan_without_json_writes_nothing_to_disk(): void
    {
        Artisan::call('gate:scan');

        $this->assertSame([], array_values(array_diff((array) scandir($this->root), ['.', '..'])));
    }

    /** @return array<string, string> file name => sha256 */
    private function hashes(string $dir): array
    {
        $hashes = [];

        foreach ((array) glob($dir.'/*.php') as $file) {
            $hash = hash_file('sha256', (string) $file);

            if ($hash === false) {
                $this->fail("Cannot hash {$file}.");
            }

            $hashes[basename((string) $file)] = $hash;
        }

        ksort($hashes);

        return $hashes;
    }
}

class ScanInertUser extends Model
{
    protected $table = 'scan_inert_users';
}
