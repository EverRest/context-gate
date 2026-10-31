<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

class OriginScenarioTest extends TestCase
{
    private const ALLOWED_ORIGIN = 'https://internal-tools.example.com';

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        config(['context-gate.http.origins' => [self::ALLOWED_ORIGIN]]);
    }

    public function test_foreign_origin_with_a_valid_token_is_refused_with_403_and_names_no_reason(): void
    {
        $response = $this->originCall('https://evil.example.com', true);

        $response->assertStatus(403);
        $this->assertStringNotContainsStringIgnoringCase('origin', (string) $response->getContent());
        $this->assertStringNotContainsStringIgnoringCase('denied', (string) $response->getContent());
    }

    public function test_request_without_origin_and_a_valid_token_is_served(): void
    {
        $this->originCall(null, true)->assertStatus(200);
    }

    public function test_allowlisted_origin_with_a_valid_token_is_served(): void
    {
        $this->originCall(self::ALLOWED_ORIGIN, true)->assertStatus(200);
    }

    /** @return TestResponse<Response> */
    private function originCall(?string $origin, bool $withToken): TestResponse
    {
        $headers = [
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ];

        if ($withToken) {
            $headers['Authorization'] = 'Bearer '.TokenFactory::create(['gate:read', 'gate:ceiling:internal'])->plainTextToken;
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
}
