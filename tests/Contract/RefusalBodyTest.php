<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

/** FR-016: 401 and 403 bodies never name the failed check and never echo a token value. */
class RefusalBodyTest extends TestCase
{
    private const ALLOWED_ORIGIN = 'https://internal-tools.example.com';

    /** Reason codes and check names that must not appear in a refusal body. */
    private const FORBIDDEN = [
        'token.', 'origin.', 'missing', 'invalid', 'expired', 'revoked', 'ceiling', 'gate:read', 'abilit', 'denied', 'no_read',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        config(['context-gate.http.origins' => [self::ALLOWED_ORIGIN]]);
    }

    /** @return iterable<string, array{string, int}> scenario => [scenario key for prepare(), status] */
    public static function refusals(): iterable
    {
        yield 'no token' => ['no token', 401];
        yield 'unknown token' => ['unknown token', 401];
        yield 'expired token' => ['expired token', 401];
        yield 'revoked token' => ['revoked token', 401];
        yield 'no gate:read' => ['no gate:read', 401];
        yield 'no ceiling' => ['no ceiling', 401];
        yield 'foreign origin' => ['foreign origin', 403];
    }

    #[DataProvider('refusals')]
    public function test_refusal_body_names_no_check_and_no_token_value(string $scenario, int $status): void
    {
        [$bearer, $origin] = $this->prepare($scenario);

        $response = $this->send($bearer, $origin);

        $response->assertStatus($status);

        $body = $response->getContent();
        $this->assertIsString($body);

        foreach (self::FORBIDDEN as $word) {
            $this->assertStringNotContainsString($word, strtolower((string) $body));
        }

        if ($bearer !== null) {
            $secret = explode('|', $bearer, 2)[1] ?? $bearer;
            $this->assertStringNotContainsString($secret, (string) $body);
        }
    }

    /** T028: a missing ability gives the same body as a missing token. */
    public function test_missing_ability_body_matches_missing_token_body(): void
    {
        $noToken = $this->send(null, null)->getContent();
        $noRead = $this->send(TokenFactory::create(['gate:ceiling:internal'])->plainTextToken, null);

        $noRead->assertStatus(401);
        $this->assertSame($noToken, $noRead->getContent());
    }

    /** @return array{0: ?string, 1: ?string} [bearer token, Origin header] */
    private function prepare(string $scenario): array
    {
        $valid = ['gate:read', 'gate:ceiling:internal'];

        return match ($scenario) {
            'no token' => [null, null],
            'unknown token' => ['1|not-a-real-token', null],
            'expired token' => [TokenFactory::create($valid, Carbon::now()->subMinute())->plainTextToken, null],
            'revoked token' => [$this->revoked($valid), null],
            'no gate:read' => [TokenFactory::create(['gate:ceiling:internal'])->plainTextToken, null],
            'no ceiling' => [TokenFactory::create(['gate:read'])->plainTextToken, null],
            'foreign origin' => [TokenFactory::create($valid)->plainTextToken, 'https://evil.example.com'],
            default => [null, null],
        };
    }

    /** @return TestResponse<Response> */
    private function send(?string $bearer, ?string $origin): TestResponse
    {
        $headers = [
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ];

        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer '.$bearer;
        }

        if ($origin !== null) {
            $headers['Origin'] = $origin;
        }

        return $this->withHeaders($headers)->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']],
        ]);
    }

    /** @param list<string> $abilities */
    private function revoked(array $abilities): string
    {
        $new = TokenFactory::create($abilities);
        $new->accessToken->delete();

        return $new->plainTextToken;
    }
}
