<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Token;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use ContextGate\Token\Ceiling;
use ContextGate\Token\TokenIssuer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use ContextGate\Token\GateAccessToken;

class TokenIssuerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
    }

    public function test_issue_writes_one_issuer_row_for_the_token(): void
    {
        $new = TokenIssuer::issue('Ana', 'ops-team', Ceiling::Internal, Carbon::now()->addDays(30));

        $this->assertDatabaseCount('gate_token_issuers', 1);
        $this->assertDatabaseHas('gate_token_issuers', ['token_id' => $new->accessToken->getKey(), 'issuer' => 'ops-team']);
    }

    public function test_issue_stores_read_and_the_single_ceiling_ability_and_expiry(): void
    {
        $new = TokenIssuer::issue('Ana', 'ops-team', Ceiling::Personal, Carbon::now()->addDays(30));

        $token = GateAccessToken::query()->findOrFail((int) $new->accessToken->getKey());
        $this->assertSame(['gate:read', 'gate:ceiling:personal'], $token->getAttribute('abilities'));
        $this->assertSame('Ana', $token->getAttribute('name'));
        $this->assertNotNull($token->getAttribute('expires_at'));
    }

    public function test_a_failed_issuer_write_leaves_no_token_behind(): void
    {
        Schema::drop('gate_token_issuers');

        $this->expectException(QueryException::class);

        try {
            TokenIssuer::issue('Ana', 'ops-team', Ceiling::Internal, Carbon::now()->addDays(30));
        } finally {
            $this->assertDatabaseCount('gate_access_tokens', 0);
        }
    }

    public function test_expiry_in_the_past_is_refused_before_a_token_is_created(): void
    {
        $this->assertRefusedWithNoRows(Carbon::now()->subMinute());
    }

    public function test_expiry_more_than_ninety_days_away_is_refused_before_a_token_is_created(): void
    {
        $this->assertRefusedWithNoRows(Carbon::now()->addDays(90)->addMinute());
    }

    public function test_expiry_of_ninety_days_is_accepted(): void
    {
        TokenIssuer::issue('Ana', 'ops-team', Ceiling::Internal, Carbon::now()->addDays(90)->subMinute());

        $this->assertDatabaseCount('gate_access_tokens', 1);
        $this->assertDatabaseCount('gate_token_issuers', 1);
    }

    public function test_issuer_longer_than_120_characters_is_refused_before_a_token_is_created(): void
    {
        try {
            TokenIssuer::issue('Ana', str_repeat('a', 121), Ceiling::Internal, Carbon::now()->addDays(30));
            $this->fail('An issuer of 121 characters must be refused.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('gate_access_tokens', 0);
        }
    }

    public function test_issuer_of_120_characters_is_accepted(): void
    {
        TokenIssuer::issue('Ana', str_repeat('a', 120), Ceiling::Internal, Carbon::now()->addDays(30));

        $this->assertDatabaseCount('gate_token_issuers', 1);
    }

    public function test_expiry_is_written_as_utc_and_read_back_as_the_same_instant_in_another_timezone(): void
    {
        config(['app.timezone' => 'Pacific/Kiritimati']);
        date_default_timezone_set('Pacific/Kiritimati');

        // An app-zone input (UTC+14): the stored value must still be the UTC wall clock of the same instant.
        $expiresAt = Carbon::now()->addDays(30)->startOfSecond();
        $new = TokenIssuer::issue('Ana', 'ops-team', Ceiling::Internal, $expiresAt);

        $this->assertSame(
            $expiresAt->copy()->utc()->format('Y-m-d H:i:s'),
            DB::table('gate_access_tokens')->value('expires_at'),
        );

        $token = GateAccessToken::query()->findOrFail((int) $new->accessToken->getKey());
        $this->assertSame($expiresAt->getTimestamp(), $token->getAttribute('expires_at')->getTimestamp());
    }

    public function test_plain_token_text_is_absent_from_the_token_row_and_the_issuer_row(): void
    {
        $new = TokenIssuer::issue('Ana', 'ops-team', Ceiling::Internal, Carbon::now()->addDays(30));

        $plain = explode('|', $new->plainTextToken, 2)[1];
        $tokenRows = json_encode(DB::table('gate_access_tokens')->get()->all(), JSON_THROW_ON_ERROR);
        $issuerRows = json_encode(DB::table('gate_token_issuers')->get()->all(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($plain, $tokenRows);
        $this->assertStringNotContainsString($plain, $issuerRows);
        $this->assertStringNotContainsString($new->plainTextToken, $issuerRows);
        $this->assertSame(hash('sha256', $plain), DB::table('gate_access_tokens')->value('token'));
    }

    public function test_revoking_the_token_keeps_its_issuer_row(): void
    {
        $new = TokenIssuer::issue('Ana', 'ops-team', Ceiling::Internal, Carbon::now()->addDays(30));

        $new->accessToken->delete();

        $this->assertDatabaseCount('gate_token_issuers', 1);
        $this->assertDatabaseHas('gate_token_issuers', ['token_id' => null, 'issuer' => 'ops-team']);
    }

    public function test_every_query_runs_on_the_token_model_connection(): void
    {
        $connections = [];
        DB::listen(function (QueryExecuted $query) use (&$connections): void {
            $connections[] = $query->connectionName;
        });

        TokenIssuer::issue('Ana', 'ops-team', Ceiling::Internal, Carbon::now()->addDays(30));

        $expected = (new GateAccessToken())->getConnectionName() ?? config('database.default');
        $this->assertNotEmpty($connections);

        foreach ($connections as $connection) {
            $this->assertSame($expected, $connection);
        }
    }

    private function assertRefusedWithNoRows(Carbon $expiresAt): void
    {
        try {
            TokenIssuer::issue('Ana', 'ops-team', Ceiling::Internal, $expiresAt);
            $this->fail('This expiry must be refused.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('gate_access_tokens', 0);
            $this->assertDatabaseCount('gate_token_issuers', 0);
        }
    }

    public function test_holder_name_over_255_characters_is_refused_before_any_row_is_written(): void
    {
        try {
            TokenIssuer::issue(str_repeat('h', 256), 'ops-team', Ceiling::Internal, Carbon::now()->addDays(30));
            $this->fail('A holder name of 256 characters must be refused.');
        } catch (InvalidArgumentException) {
        }

        $this->assertDatabaseCount('gate_token_holders', 1);
        $this->assertDatabaseCount('gate_access_tokens', 0);
        $this->assertDatabaseCount('gate_token_issuers', 0);
    }

    public function test_holder_name_of_255_characters_is_accepted(): void
    {
        $new = TokenIssuer::issue(str_repeat('h', 255), 'ops-team', Ceiling::Internal, Carbon::now()->addDays(30));

        $this->assertDatabaseCount('gate_access_tokens', 1);
        $this->assertNotSame('', $new->plainTextToken);
    }
}
