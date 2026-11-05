<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\Fixtures\UnbuildableTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings that do not validate refuse every call as config.invalid, with a receipt; they never take the host down,
 * hand a refusal to the host's own handler, or answer 500.
 */
class InvalidSettingsHttpTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Before boot, as a host's config file would have it: the provider must register and boot anyway.
        $app['config']->set('context-gate.tools', ['unbuildable' => ['enabled' => true, 'class' => UnbuildableTool::class]]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        config(['context-gate.http.origins' => []]);
        $this->app?->make('log')->listen(function ($message): void {
            $this->lines[] = (string) $message->message;
        });
    }

    public function test_a_tool_that_cannot_be_built_makes_the_settings_invalid_instead_of_failing_the_host(): void
    {
        $token = TokenFactory::create(['gate:read', 'gate:ceiling:internal', 'gate:role:support']);

        $response = $this->post_($token->plainTextToken, 'tools/call', ['name' => 'unbuildable', 'arguments' => ['text' => 'x']]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('result.isError'));
        $this->assertSame('Request refused.', $response->json('result.content.0.text'));
        $this->assertReceipt('config.invalid');
    }

    public function test_a_tool_class_that_is_not_a_tool_is_refused_per_call(): void
    {
        config(['context-gate.tools' => ['broken' => ['enabled' => true, 'class' => stdClass::class]]]);
        $token = TokenFactory::create(['gate:read', 'gate:ceiling:internal', 'gate:role:support']);

        $response = $this->post_($token->plainTextToken, 'tools/call', ['name' => 'broken', 'arguments' => []]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('result.isError'));
        $this->assertReceipt('config.invalid');
    }

    public function test_a_missing_token_under_invalid_settings_keeps_the_gate_refusal_and_its_receipt(): void
    {
        $response = $this->post_('', 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']]);

        $response->assertStatus(401);
        $response->assertHeader('WWW-Authenticate', 'Bearer');
        $this->assertSame(-32001, $response->json('error.code'));
        $this->assertReceipt('token.missing');
    }

    private function assertReceipt(string $reason): void
    {
        $reasons = array_map(static fn (string $line): mixed => (json_decode($line, true) ?: [])['reason'] ?? null, $this->lines);

        $this->assertContains($reason, $reasons, implode("\n", $this->lines));
    }

    /**
     * @param array<string, mixed> $params
     * @return TestResponse<Response>
     */
    private function post_(string $bearer, string $method, array $params): TestResponse
    {
        Auth::forgetGuards();
        $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];

        if ($bearer !== '') {
            $headers['Authorization'] = 'Bearer '.$bearer;
        }

        return $this->withHeaders($headers)->postJson('/'.config('context-gate.http.path'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }
}
