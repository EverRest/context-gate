<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** An HTTP caller is keyed token:<id>; the issuance output names its role, so the token is never issued without one. */
class TokenCallerKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');
    }

    public function test_issuance_prints_the_caller_key_and_the_role(): void
    {
        Artisan::call('gate:token', ['--role' => 'support','--holder' => 'Ivan', '--issuer' => 'Pavlo', '--ceiling' => 'internal', '--expires' => '30d']);

        $id = DB::table('gate_access_tokens')->value('id');
        $output = Artisan::output();

        $this->assertStringContainsString('token:'.$id, $output);
        $this->assertStringContainsString('Role: support', $output);
        $this->assertStringNotContainsString('callers', $output);
    }

    public function test_list_has_a_caller_column(): void
    {
        Artisan::call('gate:token', ['--role' => 'support','--holder' => 'Ivan', '--issuer' => 'Pavlo', '--ceiling' => 'internal', '--expires' => '30d']);
        $id = DB::table('gate_access_tokens')->value('id');

        Artisan::call('gate:token', ['--list' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('caller', $output);
        $this->assertStringContainsString('token:'.$id, $output);
    }
}
