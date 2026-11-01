<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** An HTTP caller is keyed token:<id> in the callers config; the command says so, so a new token is not left with zero tools. */
class TokenCallerKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');
    }

    public function test_issuance_prints_the_caller_key_and_a_callers_hint(): void
    {
        Artisan::call('gate:token', ['--holder' => 'Ivan', '--issuer' => 'Pavlo', '--ceiling' => 'internal', '--expires' => '30d']);

        $id = DB::table('gate_access_tokens')->value('id');
        $output = Artisan::output();

        $this->assertStringContainsString('token:'.$id, $output);
        $this->assertStringContainsString('callers', $output);
    }

    public function test_list_has_a_caller_column(): void
    {
        Artisan::call('gate:token', ['--holder' => 'Ivan', '--issuer' => 'Pavlo', '--ceiling' => 'internal', '--expires' => '30d']);
        $id = DB::table('gate_access_tokens')->value('id');

        Artisan::call('gate:token', ['--list' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('caller', $output);
        $this->assertStringContainsString('token:'.$id, $output);
    }
}
