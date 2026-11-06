<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use ContextGate\Token\GateAccessToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * A bearer whose id part is not a bigint must not reach the id lookup: PostgreSQL answers such an id with a query
 * error (22P02, 22003), which became a 500 with no receipt and no throttle. SQLite accepts the query, so the test
 * pins the lookup itself.
 */
class BearerIdShapeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        config(['context-gate.http.origins' => []]);
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        $secret = str_repeat('a', 40);

        yield 'non-numeric id' => ['abc|'.$secret];
        yield 'empty id' => ['|'.$secret];
        yield 'id over bigint' => ['99999999999999999999|'.$secret];
        yield 'signed id' => ['-1|'.$secret];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_id_runs_no_query(string $bearer): void
    {
        DB::enableQueryLog();

        $this->assertNull(GateAccessToken::findToken($bearer));
        $this->assertSame([], DB::getQueryLog());
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_id_is_refused_as_an_invalid_token(string $bearer): void
    {
        $this->initialize($bearer)->assertStatus(401)->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
    }

    public function test_a_well_formed_token_still_resolves(): void
    {
        $token = TokenFactory::create(['gate:read', 'gate:ceiling:internal']);

        $this->assertInstanceOf(GateAccessToken::class, GateAccessToken::findToken($token->plainTextToken));
        $this->initialize($token->plainTextToken)->assertStatus(200);
    }

    /** @return TestResponse<Response> */
    private function initialize(string $bearer): TestResponse
    {
        return $this->withHeaders([
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
            'Authorization' => 'Bearer '.$bearer,
        ])->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']],
        ]);
    }
}
