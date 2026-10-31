<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class TokenAbilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');
    }

    public function test_ability_outside_the_package_set_exits_one_and_issues_nothing(): void
    {
        $this->assertSame(1, Artisan::call('gate:token', [
            '--holder' => 'Ivan',
            '--issuer' => 'Pavlo',
            '--ceiling' => 'internal',
            '--expires' => '30d',
            '--ability' => ['gate:write'],
        ]));

        $this->assertDatabaseCount('gate_access_tokens', 0);
    }
}
