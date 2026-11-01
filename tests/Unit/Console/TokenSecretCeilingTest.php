<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Documentation says no token can carry the secret ceiling: gate:token refuses it before anything is issued. */
class TokenSecretCeilingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->never();
    }

    public function test_gate_token_refuses_a_secret_ceiling_and_issues_nothing(): void
    {
        $status = Artisan::call('gate:token', [
            '--holder' => 'Ivan',
            '--issuer' => 'Pavlo',
            '--ceiling' => 'secret',
            '--expires' => '30d',
        ]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('public, internal or personal', Artisan::output());
        $this->assertStringNotContainsString('gate:ceiling:secret', Artisan::output());
        $this->assertDatabaseCount('gate_access_tokens', 0);
        $this->assertSame(0, DB::table('gate_token_issuers')->count());
    }
}
