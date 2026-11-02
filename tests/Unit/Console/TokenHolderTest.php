<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\Fixtures\HostUser;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use ContextGate\Token\GateTokenHolder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/** Rule 1: the gate token is owned by a GateTokenHolder; the host application's users model is never used. */
class TokenHolderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        config()->set('auth.providers.users.model', HostUser::class);
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');
    }

    public function test_token_is_owned_by_a_gate_token_holder_and_no_host_user_gets_it(): void
    {
        HostUser::query()->create(['name' => 'Host Admin']);

        $this->assertSame(0, Artisan::call('gate:token', $this->issueOptions('Ivan', 'Pavlo')));

        $holder = GateTokenHolder::query()->where('name', 'Ivan')->firstOrFail();

        $this->assertDatabaseHas('gate_access_tokens', [
            'name' => 'Ivan',
            'tokenable_type' => GateTokenHolder::class,
            'tokenable_id' => $holder->getKey(),
        ]);
        $this->assertDatabaseCount('host_users', 1);
        $this->assertDatabaseMissing('gate_access_tokens', ['tokenable_type' => HostUser::class]);
    }

    public function test_issuing_succeeds_when_the_host_users_table_is_empty(): void
    {
        $this->assertSame(0, Artisan::call('gate:token', $this->issueOptions('Ivan', 'Pavlo')));

        $this->assertDatabaseCount('gate_access_tokens', 1);
    }

    public function test_the_same_holder_name_reuses_the_holder_row(): void
    {
        Artisan::call('gate:token', $this->issueOptions('Ivan', 'Pavlo'));
        Artisan::call('gate:token', $this->issueOptions('Ivan', 'Maria'));

        $this->assertDatabaseCount('gate_token_holders', 2);
        $this->assertDatabaseCount('gate_access_tokens', 2);
    }

    public function test_a_refused_issuance_leaves_no_holder_row(): void
    {
        $this->assertSame(1, Artisan::call('gate:token', $this->issueOptions('Ivan', str_repeat('a', 121))));

        $this->assertDatabaseMissing('gate_token_holders', ['name' => 'Ivan']);
        $this->assertDatabaseCount('gate_access_tokens', 0);
    }

    /** @return array<string, string> */
    private function issueOptions(string $holder, string $issuer): array
    {
        return [
            '--role' => 'support',
            '--holder' => $holder,
            '--issuer' => $issuer,
            '--ceiling' => 'internal',
            '--expires' => '30d',
        ];
    }
}
