<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

class HttpStatusMatrixTest extends TestCase
{
    private const ALLOWED_ORIGIN = 'https://internal-tools.example.com';

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        config(['context-gate.http.origins' => [self::ALLOWED_ORIGIN]]);
    }

    /** @return iterable<string, array{string, int}> scenario => [scenario key for prepare(), status] */
    public static function matrix(): iterable
    {
        yield 'no token' => ['no token', 401];
        yield 'unknown token' => ['unknown token', 401];
        yield 'expired token' => ['expired token', 401];
        yield 'revoked token' => ['revoked token', 401];
        yield 'no gate:read' => ['no gate:read', 401];
        yield 'no ceiling' => ['no ceiling', 401];
        yield 'two ceilings' => ['two ceilings', 401];
        yield 'foreign origin' => ['foreign origin', 403];
        yield 'allowlisted origin' => ['allowlisted origin', 200];
        yield 'no origin' => ['no origin', 200];
    }

    #[DataProvider('matrix')]
    public function test_status_matches_the_access_policy(string $scenario, int $status): void
    {
        [$token, $origin] = $this->prepare($scenario);

        $headers = [
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ];

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        if ($origin !== null) {
            $headers['Origin'] = $origin;
        }

        $response = $this->withHeaders($headers)->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']],
        ]);

        $response->assertStatus($status);
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
            'two ceilings' => [TokenFactory::create(['gate:read', 'gate:ceiling:public', 'gate:ceiling:internal'])->plainTextToken, null],
            'foreign origin' => [TokenFactory::create($valid)->plainTextToken, 'https://evil.example.com'],
            'allowlisted origin' => [TokenFactory::create($valid)->plainTextToken, self::ALLOWED_ORIGIN],
            'no origin' => [TokenFactory::create($valid)->plainTextToken, null],
            default => [null, null],
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
