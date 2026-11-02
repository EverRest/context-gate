<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Console;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** FR-010, SC-003: --list shows metadata for active tokens and never a token value. */
class TokenListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');
    }

    public function test_list_shows_id_holder_issuer_ceiling_expiry_and_issue_date_for_each_token(): void
    {
        $this->issue('Ivan', 'Pavlo', 'internal');
        $this->issue('Maria', 'Olena', 'public');

        $this->assertSame(0, Artisan::call('gate:token', ['--list' => true]));

        $output = Artisan::output();

        foreach (['id', 'holder', 'issuer', 'ceiling', 'expires_at', 'issued_at'] as $header) {
            $this->assertStringContainsString($header, $output);
        }

        $this->assertStringContainsString('Ivan', $output);
        $this->assertStringContainsString('Pavlo', $output);
        $this->assertStringContainsString('internal', $output);
        $this->assertStringContainsString('Maria', $output);
        $this->assertStringContainsString('Olena', $output);
        $this->assertStringContainsString('public', $output);
        $this->assertStringContainsString(substr((string) DB::table('gate_access_tokens')->value('expires_at'), 0, 10), $output);
    }

    public function test_list_never_shows_a_token_value(): void
    {
        $plain = $this->issue('Ivan', 'Pavlo', 'internal');

        Artisan::call('gate:token', ['--list' => true]);

        $this->assertStringNotContainsString($plain, Artisan::output());
    }

    /** Issues a token through the command and returns its plain text value, shown once. */
    private function issue(string $holder, string $issuer, string $ceiling): string
    {
        Artisan::call('gate:token', [
            '--role' => 'support',
            '--holder' => $holder,
            '--issuer' => $issuer,
            '--ceiling' => $ceiling,
            '--expires' => '30d',
        ]);

        $this->assertSame(1, preg_match('/\d+\|\S+/', Artisan::output(), $matches));

        return $matches[0] ?? '';
    }
}
