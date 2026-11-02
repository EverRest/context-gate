<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class TokenMaxExpiryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');
    }

    public function test_expires_above_ninety_days_exits_one_naming_the_maximum(): void
    {
        $this->assertSame(1, Artisan::call('gate:token', $this->issueOptions('120d')));

        $this->assertStringContainsString('90d', Artisan::output());
        $this->assertDatabaseCount('gate_access_tokens', 0);
    }

    public function test_expires_of_ninety_days_is_accepted(): void
    {
        $this->assertSame(0, Artisan::call('gate:token', $this->issueOptions('90d')));

        $this->assertDatabaseCount('gate_access_tokens', 1);
        $this->assertDatabaseCount('gate_token_issuers', 1);
    }

    /** @return array<string, string> */
    private function issueOptions(string $expires): array
    {
        return [
            '--role' => 'support',
            '--holder' => 'Ivan',
            '--issuer' => 'Pavlo',
            '--ceiling' => 'internal',
            '--expires' => $expires,
        ];
    }
}
