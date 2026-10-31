<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Http;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/** Spec 005 FR-006 (defence in depth at the guard): a gate token without a valid expiry inside 90 days is refused. */
class GateTokenExpiryGuardTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        config(['context-gate.http.origins' => []]);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
    }

    public function test_token_with_a_null_expiry_is_refused(): void
    {
        $this->assertMcpStatus(TokenFactory::createWithoutExpiry(['gate:read', 'gate:ceiling:internal'])->plainTextToken, 401);
        $this->assertSame('token.invalid', $this->lastReason());
    }

    public function test_token_expiring_ten_years_out_is_refused(): void
    {
        $token = TokenFactory::create(['gate:read', 'gate:ceiling:internal'], Carbon::now()->addYears(10))->plainTextToken;

        $this->assertMcpStatus($token, 401);
        $this->assertSame('token.invalid', $this->lastReason());
    }

    public function test_token_expiring_within_ninety_days_is_accepted(): void
    {
        $token = TokenFactory::create(['gate:read', 'gate:ceiling:internal'], Carbon::now()->addDays(30))->plainTextToken;

        $this->assertMcpStatus($token, 200);
    }

    private function assertMcpStatus(string $bearer, int $status): void
    {
        $this->withHeaders([
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
            'Authorization' => 'Bearer '.$bearer,
        ])->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']],
        ])->assertStatus($status);
    }

    private function lastReason(): string
    {
        $receipt = json_decode((string) end($this->lines), true, 512, JSON_THROW_ON_ERROR);

        return (string) $receipt['reason'];
    }
}
