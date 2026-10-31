<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/** FR-010 in UTC+14: --list filters active tokens against UTC now and prints expiry as the UTC instant. */
class TokenListTimezoneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');

        config(['app.timezone' => 'Pacific/Kiritimati']);
        date_default_timezone_set('Pacific/Kiritimati');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');

        parent::tearDown();
    }

    public function test_list_hides_a_token_expired_in_utc_and_shows_one_still_active(): void
    {
        TokenFactory::create(['gate:read', 'gate:ceiling:internal'], Carbon::now('UTC')->subMinutes(10), 'Expired Holder');
        TokenFactory::create(['gate:read', 'gate:ceiling:internal'], Carbon::now('UTC')->addHours(2), 'Active Holder');

        $this->assertSame(0, Artisan::call('gate:token', ['--list' => true]));

        $this->assertStringContainsString('Active Holder', Artisan::output());
        $this->assertStringNotContainsString('Expired Holder', Artisan::output());
    }

    public function test_list_prints_the_expiry_instant_in_utc(): void
    {
        $expiresAt = Carbon::now('UTC')->addHours(2)->startOfSecond();
        TokenFactory::create(['gate:read', 'gate:ceiling:internal'], $expiresAt, 'Active Holder');

        Artisan::call('gate:token', ['--list' => true]);

        $this->assertStringContainsString($expiresAt->format('Y-m-d\TH:i:s\Z'), Artisan::output());
    }
}
