<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use ContextGate\Tests\Fixtures\Runbook\SecretsRunbook;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** spec 018 FR-009, SC-002: no seeded env, config, cache or database value appears in console or MCP output. */
class NoValueInOutputTest extends TestCase
{
    use RunbookToolHarness;

    private const SEEDS = ['FAKE_FIXTURE_ONLY_ENV', 'FAKE_FIXTURE_ONLY_CONFIG', 'FAKE_FIXTURE_ONLY_CACHE', 'FAKE_FIXTURE_ONLY_DB'];

    protected function setUp(): void
    {
        parent::setUp();

        putenv('APP_KEY=FAKE_FIXTURE_ONLY_ENV');
        config(['services.seeded.token' => 'FAKE_FIXTURE_ONLY_CONFIG']);
        Cache::put('seeded', 'FAKE_FIXTURE_ONLY_CACHE');

        Schema::create('failed_jobs', function ($table): void {
            $table->id();
            $table->text('payload');
        });
        DB::table('failed_jobs')->insert(['payload' => 'FAKE_FIXTURE_ONLY_DB']);
    }

    protected function tearDown(): void
    {
        putenv('APP_KEY');

        parent::tearDown();
    }

    public function test_no_seeded_value_reaches_the_console_or_the_mcp_response(): void
    {
        config(['context-gate.runbooks' => ['secrets' => SecretsRunbook::class]]);
        $this->configureRunbookTool(SecretsRunbook::class, 'secrets');

        Artisan::call('gate:runbook', ['name' => 'secrets']);
        $console = Artisan::output();
        $mcp = json_encode($this->rawCall('runbook.secrets'), JSON_THROW_ON_ERROR);

        foreach (self::SEEDS as $seed) {
            $this->assertStringNotContainsString($seed, $console);
            $this->assertStringNotContainsString($seed, $mcp);
        }

        $this->assertStringContainsString('APP_KEY is set', $console);
        $this->assertStringContainsString('failed job count is 1', $console);
    }
}
