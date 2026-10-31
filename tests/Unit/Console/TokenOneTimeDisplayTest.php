<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class TokenOneTimeDisplayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');
    }

    public function test_token_value_is_printed_once_with_a_notice_that_it_will_not_be_shown_again(): void
    {
        $this->assertSame(0, Artisan::call('gate:token', [
            '--holder' => 'Ivan',
            '--issuer' => 'Pavlo',
            '--ceiling' => 'internal',
            '--expires' => '30d',
        ]));

        $output = Artisan::output();

        $this->assertStringContainsString('shown once', $output);
        $this->assertSame(1, preg_match_all('/\d+\|\S+/', $output));
    }
}
