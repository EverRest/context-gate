<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Token;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TokenHashStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');
    }

    public function test_the_token_row_holds_a_hash_and_not_the_plain_token(): void
    {
        Artisan::call('gate:token', [
            '--role' => 'support',
            '--holder' => 'Ivan',
            '--issuer' => 'Pavlo',
            '--ceiling' => 'internal',
            '--expires' => '30d',
        ]);

        $output = Artisan::output();

        preg_match('/\d+\|([A-Za-z0-9]+)/', $output, $match);
        $plain = $match[1] ?? '';
        $this->assertNotSame('', $plain);

        $rows = json_encode(DB::table('gate_access_tokens')->get()->all(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($plain, $rows);
        $this->assertSame(hash('sha256', $plain), DB::table('gate_access_tokens')->value('token'));
    }
}
