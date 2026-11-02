<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Roles;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use ContextGate\Token\GateAccessToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/** spec 010 FR-006, FR-013, US1 scenarios 3, 4, 5, 6; contracts/roles-and-token-command.md. */
class TokenCommandRoleTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        config(['context-gate.roles' => ['support' => ['tools' => [], 'ceiling' => 'internal']]]);
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
    }

    /** @param array<string, string> $extra */
    private function issue(array $extra = []): int
    {
        return Artisan::call('gate:token', $extra + ['--holder' => 'Ivan', '--issuer' => 'Pavlo', '--expires' => '30d']);
    }

    public function test_a_missing_role_exits_1_and_issues_nothing(): void
    {
        $this->assertSame(1, $this->issue(['--ceiling' => 'internal']));
        $this->assertDatabaseCount('gate_access_tokens', 0);
    }

    public function test_an_undefined_role_exits_1_and_issues_nothing(): void
    {
        $this->assertSame(1, $this->issue(['--role' => 'nobody']));
        $this->assertDatabaseCount('gate_access_tokens', 0);
    }

    public function test_without_a_ceiling_the_token_stores_the_role_ceiling(): void
    {
        $this->assertSame(0, $this->issue(['--role' => 'support']));

        $token = GateAccessToken::query()->firstOrFail();
        $this->assertContains('gate:ceiling:internal', $token->getAttribute('abilities'));
        $this->assertContains('gate:role:support', $token->getAttribute('abilities'));
    }

    public function test_a_narrower_ceiling_is_stored_as_given(): void
    {
        $this->assertSame(0, $this->issue(['--role' => 'support', '--ceiling' => 'public']));

        $this->assertContains('gate:ceiling:public', GateAccessToken::query()->firstOrFail()->getAttribute('abilities'));
    }

    public function test_a_wider_ceiling_exits_1_and_issues_nothing(): void
    {
        $this->assertSame(1, $this->issue(['--role' => 'support', '--ceiling' => 'personal']));
        $this->assertDatabaseCount('gate_access_tokens', 0);
    }

    public function test_the_output_names_the_role_and_has_no_callers_line(): void
    {
        $this->issue(['--role' => 'support']);

        $output = Artisan::output();
        $this->assertStringContainsString('Role: support', $output);
        $this->assertStringNotContainsString('callers', $output);
    }

    public function test_the_issued_receipt_records_the_role(): void
    {
        $this->issue(['--role' => 'support']);

        $receipt = json_decode((string) end($this->lines), true);
        $this->assertSame('token.issued', $receipt['event']);
        $this->assertSame('support', $receipt['role']);
    }

    public function test_list_shows_the_role_and_none_for_a_token_without_one(): void
    {
        $this->issue(['--role' => 'support']);
        TokenFactory::create(['gate:read', 'gate:ceiling:internal'], null, 'Old');

        Artisan::call('gate:token', ['--list' => true, '--json' => true]);
        $rows = json_decode(Artisan::output(), true);

        $roles = array_column($rows, 'role', 'holder');
        $this->assertSame('support', $roles['Ivan']);
        $this->assertSame('none', $roles['Old']);
    }
}
