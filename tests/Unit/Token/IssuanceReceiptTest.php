<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Token;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IssuanceReceiptTest extends TestCase
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

    public function test_token_issued_receipt_holds_metadata_and_no_token_value(): void
    {
        Artisan::call('gate:token', [
            '--role' => 'support',
            '--holder' => 'Ivan (on-call)',
            '--issuer' => 'Pavlo',
            '--ceiling' => 'internal',
            '--expires' => '30d',
        ]);

        $this->assertCount(1, $this->lines);
        $line = $this->lines[0];
        $receipt = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('token.issued', $receipt['event']);
        $this->assertSame('Pavlo', $receipt['issuer']);
        $this->assertSame('Ivan (on-call)', $receipt['holder']);
        $this->assertSame('internal', $receipt['ceiling']);
        $this->assertArrayHasKey('expires_at', $receipt);
        $this->assertSame((string) DB::table('gate_access_tokens')->value('id'), (string) $receipt['token_id']);

        $output = Artisan::output();

        $this->assertStringNotContainsString(trim(Str::after($output, '|')), $line);
    }
}
