<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

/** User Story 2 scenarios 1-5: every refused token is a 401 that carries a WWW-Authenticate header (SC-001). */
class TokenRefusalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        config(['context-gate.http.origins' => []]);
    }

    /** @return iterable<string, array{string}> scenario => [scenario key for bearerFor()] */
    public static function refusedTokens(): iterable
    {
        yield 'no token' => ['no token'];
        yield 'expired token' => ['expired token'];
        yield 'revoked token' => ['revoked token'];
        yield 'no gate:read' => ['no gate:read'];
        yield 'no ceiling' => ['no ceiling'];
    }

    #[DataProvider('refusedTokens')]
    public function test_refused_token_gets_401_with_www_authenticate(string $scenario): void
    {
        $headers = [
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ];

        $bearer = $this->bearerFor($scenario);

        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer '.$bearer;
        }

        $response = $this->withHeaders($headers)->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']],
        ]);

        $response->assertStatus(401);
        $this->assertNotNull($response->headers->get('WWW-Authenticate'));
    }

    private function bearerFor(string $scenario): ?string
    {
        $valid = ['gate:read', 'gate:ceiling:internal'];

        return match ($scenario) {
            'no token' => null,
            'expired token' => TokenFactory::create($valid, Carbon::now()->subMinute())->plainTextToken,
            'revoked token' => $this->revoked($valid),
            'no gate:read' => TokenFactory::create(['gate:ceiling:internal'])->plainTextToken,
            'no ceiling' => TokenFactory::create(['gate:read'])->plainTextToken,
            default => null,
        };
    }

    /** @param list<string> $abilities */
    private function revoked(array $abilities): string
    {
        $new = TokenFactory::create($abilities);
        $new->accessToken->delete();

        return $new->plainTextToken;
    }
}
