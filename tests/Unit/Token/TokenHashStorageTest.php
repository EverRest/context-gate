<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Token;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
            '--holder' => 'Ivan',
            '--issuer' => 'Pavlo',
            '--ceiling' => 'internal',
            '--expires' => '30d',
        ]);

        $output = Artisan::output();

        $this->assertStringContainsString('|', $output);
        $plain = trim(Str::after($output, '|'));

        $rows = json_encode(DB::table('gate_access_tokens')->get()->all(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($plain, $rows);
        $this->assertSame(hash('sha256', $plain), DB::table('gate_access_tokens')->value('token'));
    }
}
