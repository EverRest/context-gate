<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\CustomerContract;
use ContextGate\Tests\Fixtures\DayPseudonymCustomerContract;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** US1 scenario 4, FR-004: gate:audit writes no file and no row. */
class AuditReadOnlyTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
        });
        DB::table('customers')->insert(['id' => 1, 'name' => 'Ada', 'email' => null]);

        config(['context-gate.classification' => [
            'customers' => ['customer' => 'internal', 'email' => 'internal', 'name' => 'personal'],
        ]]);
        config(['context-gate.tools' => [
            'probe' => ['enabled' => true, 'class' => ProbeTool::class, 'contract' => CustomerContract::class, 'risk' => 'low'],
            'echo' => ['enabled' => true, 'class' => EchoTool::class, 'contract' => DayPseudonymCustomerContract::class, 'risk' => 'high'],
        ]]);

        $this->files = [__DIR__.'/../../config/context-gate.php', ...(glob(__DIR__.'/../../database/migrations/*.php') ?: [])];
    }

    public function test_file_times_and_row_counts_are_unchanged_after_the_audit(): void
    {
        clearstatcache();
        $before = array_map(filemtime(...), $this->files);
        $rows = DB::table('customers')->count();

        $this->assertSame(0, Artisan::call('gate:audit'));
        $this->assertSame(0, Artisan::call('gate:audit', ['--json' => true]));

        clearstatcache();
        $this->assertSame($before, array_map(filemtime(...), $this->files));
        $this->assertSame($rows, DB::table('customers')->count());
    }
}
