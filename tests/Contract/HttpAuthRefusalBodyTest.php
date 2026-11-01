<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

/** Contract Responses table, spec 005 T028: the 403 for a token without gate:read has the same body as the 401 for no token. */
class HttpAuthRefusalBodyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        config(['context-gate.http.origins' => ['https://internal-tools.example.com']]);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');
    }

    public function test_a_token_without_gate_read_has_the_same_body_as_a_missing_token(): void
    {
        $noRead = TokenFactory::create(['gate:ceiling:internal']);

        $missing = $this->callWithBearer(bearer: '');
        $forbidden = $this->callWithBearer(bearer: $noRead->plainTextToken);

        $missing->assertStatus(401);
        $forbidden->assertStatus(403);

        $this->assertSame(array_keys($missing->json()), array_keys($forbidden->json()));
        $this->assertSame($missing->json('error.message'), $forbidden->json('error.message'));
        $this->assertSame($missing->json(), $forbidden->json());
    }

    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function callWithBearer(string $bearer): TestResponse
    {
        Auth::forgetGuards();
        $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];

        if ($bearer !== '') {
            $headers['Authorization'] = 'Bearer '.$bearer;
        }

        return $this->withHeaders($headers)->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']],
        ]);
    }
}
