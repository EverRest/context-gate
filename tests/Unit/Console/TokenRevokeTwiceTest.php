<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Edge case: revoking an id that is already revoked reports it and exits 1, changing nothing further. */
class TokenRevokeTwiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');
    }

    public function test_revoking_an_already_revoked_id_reports_it_and_exits_1(): void
    {
        Artisan::call('gate:token', [
            '--holder' => 'Ivan',
            '--issuer' => 'Pavlo',
            '--ceiling' => 'internal',
            '--expires' => '30d',
        ]);
        $id = (string) DB::table('gate_access_tokens')->value('id');

        $this->assertSame(0, Artisan::call('gate:token', ['--revoke' => $id]));
        $this->assertSame(1, Artisan::call('gate:token', ['--revoke' => $id]));

        $this->assertStringContainsString('already revoked', Artisan::output());
        $this->assertDatabaseCount('gate_access_tokens', 0);
        $this->assertDatabaseHas('gate_token_issuers', ['issuer' => 'Pavlo', 'token_id' => null]);
    }
}
