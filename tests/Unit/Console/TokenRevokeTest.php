<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** FR-011: --revoke deletes the gate token, keeps its issuer row without a token, and records token.revoked. */
class TokenRevokeTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        Log::shouldReceive('channel')->with(config('context-gate.channel'))->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
    }

    public function test_revoke_deletes_the_token_and_keeps_the_issuer_row_with_a_null_token_id(): void
    {
        $this->issue();
        $id = (int) DB::table('gate_access_tokens')->value('id');

        $this->assertSame(0, Artisan::call('gate:token', ['--revoke' => (string) $id]));

        $this->assertDatabaseMissing('gate_access_tokens', ['id' => $id]);
        $this->assertDatabaseCount('gate_token_issuers', 1);
        $this->assertDatabaseHas('gate_token_issuers', ['issuer' => 'Pavlo', 'token_id' => null]);
    }

    public function test_revoke_writes_a_token_revoked_receipt_with_no_token_value(): void
    {
        $plain = $this->issue();
        $id = (int) DB::table('gate_access_tokens')->value('id');

        Artisan::call('gate:token', ['--revoke' => (string) $id]);

        $this->assertCount(1, $this->lines);
        $line = $this->lines[0] ?? '';
        $receipt = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('token.revoked', $receipt['event']);
        $this->assertSame('Pavlo', $receipt['issuer']);
        $this->assertSame($id, $receipt['token_id']);
        $keys = array_keys($receipt);
        sort($keys);
        $this->assertSame(['at', 'event', 'holder', 'id', 'issuer', 'token_id'], $keys);
        $this->assertSame('Ivan', $receipt['holder']);
        $this->assertStringNotContainsString(explode('|', $plain)[1], $line);
    }

    public function test_an_unknown_id_exits_1_and_changes_nothing(): void
    {
        $this->issue();
        $id = (int) DB::table('gate_access_tokens')->value('id');

        $this->assertSame(1, Artisan::call('gate:token', ['--revoke' => (string) ($id + 100)]));

        $this->assertDatabaseCount('gate_access_tokens', 1);
        $this->assertDatabaseHas('gate_token_issuers', ['issuer' => 'Pavlo', 'token_id' => $id]);
        $this->assertSame([], $this->lines);
    }

    /** Issues a token through the command and returns its plain text value, shown once. */
    private function issue(): string
    {
        Artisan::call('gate:token', [
            '--holder' => 'Ivan',
            '--issuer' => 'Pavlo',
            '--ceiling' => 'internal',
            '--expires' => '30d',
        ]);

        $this->assertSame(1, preg_match('/\d+\|\S+/', Artisan::output(), $matches));
        $this->lines = [];

        return $matches[0] ?? '';
    }
}
