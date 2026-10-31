<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\ReasonCode;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

/** Constitution IV: each refused HTTP call leaves exactly one receipt with its reason and no token value. */
class HttpRefusalReceiptTest extends TestCase
{
    private const ALLOWED_ORIGIN = 'https://internal-tools.example.com';

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        config(['context-gate.http.origins' => [self::ALLOWED_ORIGIN]]);

        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
    }

    /** @return iterable<string, array{string, string, string|null, string}> scenario => [bearer, reason, origin, caller] */
    public static function refusals(): iterable
    {
        yield 'no token' => ['', 'token.missing', null, 'unknown'];
        yield 'unknown token' => ['1|not-a-real-token', 'token.invalid', null, 'unknown'];
        yield 'expired token' => ['expired', 'token.invalid', null, 'unknown'];
        yield 'revoked token' => ['revoked', 'token.invalid', null, 'unknown'];
        yield 'no gate:read' => ['no_read', 'token.no_read', null, 'token'];
        yield 'no ceiling' => ['no_ceiling', 'token.no_ceiling', null, 'token'];
        yield 'foreign origin' => ['valid', 'origin.denied', 'https://evil.example.com', 'token'];
    }

    #[DataProvider('refusals')]
    public function test_each_refused_http_call_writes_one_receipt_with_its_reason(string $scenario, string $reason, ?string $origin, string $caller): void
    {
        [$bearer, $tokenId] = $this->bearerFor($scenario);

        $headers = [
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ];

        if ($bearer !== '') {
            $headers['Authorization'] = 'Bearer '.$bearer;
        }

        if ($origin !== null) {
            $headers['Origin'] = $origin;
        }

        $this->withHeaders($headers)->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']],
        ])->assertStatus(in_array($reason, ['origin.denied'], true) ? 403 : 401);

        $this->assertCount(1, $this->lines);

        $receipt = json_decode($this->lines[0], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($reason, $receipt['reason']);
        $this->assertSame(ReasonCode::from($reason)->decision(), $receipt['decision']);
        $this->assertSame($caller === 'unknown' ? 'unknown' : 'token:'.$tokenId, $receipt['caller']);
        if ($bearer !== '') {
            $this->assertStringNotContainsString($bearer, $this->lines[0]);
        }
    }

    /** @return array{0: string, 1: string} [bearer token, token id] */
    private function bearerFor(string $scenario): array
    {
        $valid = ['gate:read', 'gate:ceiling:internal'];

        return match ($scenario) {
            '' => ['', ''],
            '1|not-a-real-token' => ['1|not-a-real-token', ''],
            'expired' => $this->issue($valid, Carbon::now()->subMinute()),
            'revoked' => $this->revoked($valid),
            'no_read' => $this->issue(['gate:ceiling:internal']),
            'no_ceiling' => $this->issue(['gate:read']),
            default => $this->issue($valid),
        };
    }

    /**
     * @param list<string> $abilities
     * @return array{0: string, 1: string}
     */
    private function issue(array $abilities, ?Carbon $expiresAt = null): array
    {
        $new = TokenFactory::create($abilities, $expiresAt);

        return [$new->plainTextToken, (string) $new->accessToken->getKey()];
    }

    /** @param list<string> $abilities
     * @return array{0: string, 1: string}
     */
    private function revoked(array $abilities): array
    {
        $new = TokenFactory::create($abilities);
        $new->accessToken->delete();

        return [$new->plainTextToken, (string) $new->accessToken->getKey()];
    }
}
