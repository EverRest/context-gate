<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\Fingerprint;
use ContextGate\Server\GateServer;
use ContextGate\Tests\Fixtures\CustomerContract;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use LogicException;

/**
 * Characterization of the visibility and policy decisions (spec 007, T050). Each case drives the server the way a
 * client does and records the answer and the receipt. The same expectations must hold before and after the
 * extraction of PolicyStep (T051): a change here means the behaviour changed.
 */
class PolicyStepTest extends TestCase
{
    /** @var list<string> */
    private array $receipts = [];

    public function test_unknown_tool_is_not_found_with_hidden_receipt_and_no_tool_name(): void
    {
        $this->configure(['echo' => $this->entry()]);

        [$response, $receipt] = $this->callTool('nope', ['text' => 'hi']);

        $this->assertSame(-32602, $response['error']['code']);
        $this->assertSame('Tool not found.', $response['error']['message']);
        $this->assertSame('hidden', $receipt['decision']);
        $this->assertSame('policy.hidden', $receipt['reason']);
        $this->assertNull($receipt['tool']);
    }

    public function test_tool_the_caller_may_not_use_is_not_found_with_hidden_receipt(): void
    {
        $this->configure(['echo' => $this->entry()], callerTools: ['other']);

        [$response, $receipt] = $this->callTool('echo', ['text' => 'hi']);

        $this->assertSame('Tool not found.', $response['error']['message']);
        $this->assertSame('hidden', $receipt['decision']);
        $this->assertSame('policy.hidden', $receipt['reason']);
        $this->assertSame('echo', $receipt['tool']);
    }

    public function test_pin_mismatch_is_not_found_with_pin_mismatch_receipt(): void
    {
        $this->configure(['echo' => $this->entry(fingerprint: 'not-the-fingerprint')]);

        [$response, $receipt] = $this->callTool('echo', ['text' => 'hi']);

        $this->assertSame('Tool not found.', $response['error']['message']);
        $this->assertSame('hidden', $receipt['decision']);
        $this->assertSame('tool.pin_mismatch', $receipt['reason']);
        $this->assertSame('echo', $receipt['tool']);
    }

    public function test_gate_off_is_not_found_with_hidden_receipt_and_no_tool_name(): void
    {
        $this->configure(['echo' => $this->entry()], enabled: false);

        [$response, $receipt] = $this->callTool('echo', ['text' => 'hi']);

        $this->assertSame('Tool not found.', $response['error']['message']);
        $this->assertSame('policy.hidden', $receipt['reason']);
        $this->assertNull($receipt['tool']);
    }

    public function test_missing_name_is_invalid_params_with_input_invalid_receipt(): void
    {
        $this->configure(['echo' => $this->entry()]);

        [$response, $receipt] = $this->callTool(null, ['text' => 'hi']);

        $this->assertSame(-32602, $response['error']['code']);
        $this->assertSame('Missing or invalid parameter [name].', $response['error']['message']);
        $this->assertSame('input.invalid', $receipt['reason']);
        $this->assertNull($receipt['tool']);
    }

    public function test_invalid_arguments_are_a_tool_error_with_input_invalid_receipt(): void
    {
        $this->configure(['echo' => $this->entry()]);

        [$response, $receipt] = $this->callTool('echo', []);

        $this->assertTrue($response['result']['isError']);
        $this->assertStringStartsWith('Invalid arguments: ', $response['result']['content'][0]['text']);
        $this->assertSame('input.invalid', $receipt['reason']);
        $this->assertSame('echo', $receipt['tool']);
    }

    public function test_denied_policy_is_a_generic_refusal_with_policy_denied_receipt(): void
    {
        Gate::define('echo.deny', fn (): bool => false);
        $this->configure(['echo' => $this->entry(gate: 'echo.deny')]);

        [$response, $receipt] = $this->callTool('echo', ['text' => 'hi']);

        $this->assertTrue($response['result']['isError']);
        $this->assertSame('Request refused.', $response['result']['content'][0]['text']);
        $this->assertSame('denied', $receipt['decision']);
        $this->assertSame('policy.denied', $receipt['reason']);
        $this->assertSame('echo', $receipt['tool']);
    }

    public function test_allowed_call_has_no_visibility_or_policy_reason(): void
    {
        Gate::define('echo.allow', fn (): bool => true);
        app()->instance('context-gate.ceiling', 'internal');
        config(['context-gate.classification' => ['customers' => ['customer' => 'public', 'email' => 'internal']]]);
        $this->configure(['echo' => $this->entry(contract: CustomerContract::class)]);

        [$response, $receipt] = $this->callTool('echo', ['text' => 'hi']);

        // The handler runs and the call fails later, in the firewall (EchoTool returns plain text, not JSON), so the
        // receipt is "failed". What matters here: the visibility and policy steps let the call through.
        $this->assertArrayNotHasKey('error', $response);
        $this->assertNotSame('Request refused.', $response['result']['content'][0]['text'] ?? null);
        $this->assertNotContains($receipt['decision'], ['hidden', 'denied']);
        $this->assertNotContains($receipt['reason'], ['policy.hidden', 'tool.pin_mismatch', 'policy.denied', 'input.invalid']);
        $this->assertSame('echo', $receipt['tool']);
    }

    /** @return array<string, mixed> */
    private function entry(string $fingerprint = '', string $gate = 'echo.allow', ?string $contract = null): array
    {
        $entry = [
            'enabled' => true,
            'class' => EchoTool::class,
            'gate' => $gate,
            'fingerprint' => $fingerprint === '' ? Fingerprint::of(new EchoTool()) : $fingerprint,
        ];

        return $contract === null ? $entry : $entry + ['contract' => $contract];
    }

    /**
     * @param array<string, array<string, mixed>> $tools
     * @param list<string> $callerTools
     */
    private function configure(array $tools, array $callerTools = ['echo'], bool $enabled = true): void
    {
        $this->receipts = [];
        Gate::define('echo.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => $callerTools]);
        config(['context-gate.enabled' => $enabled, 'context-gate.tools' => $tools]);

        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->receipts[] = $line;
        });
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} the JSON-RPC response and the one receipt
     */
    private function callTool(?string $name, array $arguments): array
    {
        $params = ['arguments' => $arguments];

        if ($name !== null) {
            $params['name'] = $name;
        }

        $response = $this->send(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params], JSON_THROW_ON_ERROR))[0];

        return [
            json_decode($response, true, 512, JSON_THROW_ON_ERROR),
            json_decode($this->receipts[0] ?? throw new LogicException('The call wrote no receipt.'), true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    /** @return list<string> */
    private function send(string ...$messages): array
    {
        $transport = new class () extends FakeTransporter {
            /** @var list<string> */
            public array $sent = [];

            public function send(string $message): void
            {
                $this->sent[] = $message;
            }
        };

        $server = new GateServer($transport);
        $server->start();

        foreach ($messages as $message) {
            $server->handle($message);
        }

        return $transport->sent;
    }
}
