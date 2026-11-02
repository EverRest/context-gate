<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Settings;

use ContextGate\Server\Fingerprint;
use ContextGate\Tests\Contract\GateCallHarness;
use ContextGate\Tests\Fixtures\ProbeContract;
use ContextGate\Tests\Fixtures\ProbeTool;
use ContextGate\Tests\Fixtures\TokenFactory;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

/**
 * spec 009 amendment 2026-11-02: over HTTP, a call under invalid settings is answered by the MCP route with the
 * config.invalid refusal and a receipt; the route exists, and boot does not fail on the invalid value.
 */
class HttpInvalidSettingsTest extends TestCase
{
    use GateCallHarness;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Set before the provider boots, so the route is registered under invalid settings.
        $app['config']->set('context-gate.firewall.ceiling', 'secret');
    }

    protected function setUp(): void
    {
        parent::setUp();

        TokenFactory::migrate();
        Gate::define('echo.allow', fn (): bool => true);
        ProbeTool::$called = false;
    }

    public function test_a_call_over_http_under_invalid_settings_is_refused_as_config_invalid_with_a_receipt(): void
    {
        config(['context-gate.enabled' => true, 'context-gate.tools' => ['probe' => [
            'enabled' => true,
            'class' => ProbeTool::class,
            'contract' => ProbeContract::class,
            'gate' => 'echo.allow',
            'fingerprint' => Fingerprint::of(new ProbeTool()),
        ]]]);
        $token = TokenFactory::create(['gate:read', 'gate:ceiling:public']);
        config(['context-gate.callers.token:'.$token->accessToken->getKey().'.tools' => ['probe']]);
        $this->recordLines();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ])->postJson('/'.config('context-gate.http.path'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'probe', 'arguments' => ['text' => 'x']],
        ]);

        $response->assertOk();
        $result = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['result'];
        $this->assertTrue($result['isError']);
        $this->assertFalse(ProbeTool::$called);
        $this->assertSame('config.invalid', $this->lastReceipt()['reason']);
    }
}
