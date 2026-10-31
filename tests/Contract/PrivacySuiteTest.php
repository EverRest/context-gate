<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Server\GateServer;
use ContextGate\Tests\Fixtures\CanaryValues;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\Fixtures\SeededPii;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Transport\FakeTransporter;

/**
 * Privacy gate (SC-001, SC-003, constitution IX). A tool returns a row with every seeded personal value and
 * every canary; none of them may appear in the response or in the receipt. Expected red until the whole
 * firewall pipeline (T086) is wired.
 */
class PrivacySuiteTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    public function test_no_seeded_value_or_canary_reaches_the_response_or_the_receipt(): void
    {
        Gate::define('echo.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['customer_lookup']]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'customer_lookup' => [
                'enabled' => true,
                'class' => CustomerLookupTool::class,
                'gate' => 'echo.allow',
                'fingerprint' => hash('sha256', (new CustomerLookupTool())->description()),
            ],
        ]]);
        $this->recordLines();

        $raw = $this->send(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'customer_lookup', 'arguments' => ['text' => 'USR_7f3a']],
        ], JSON_THROW_ON_ERROR))[0];

        foreach ($this->seededValues() as $label => $value) {
            $this->assertStringNotContainsString($value, $raw, "response leaks {$label}");

            foreach ($this->lines as $line) {
                $this->assertStringNotContainsString($value, $line, "receipt leaks {$label}");
            }
        }
    }

    /** @return array<string, string> */
    private function seededValues(): array
    {
        return [
            'email' => SeededPii::EMAIL,
            'phone' => SeededPii::PHONE,
            'iban' => SeededPii::IBAN,
            'card' => SeededPii::CARD,
            'bearer token' => SeededPii::BEARER_TOKEN,
            'canary email' => CanaryValues::EMAIL,
            'canary token' => CanaryValues::TOKEN,
        ];
    }

    private function recordLines(): void
    {
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info', 'error')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
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

final class CustomerLookupTool extends EchoTool
{
    public function name(): string
    {
        return 'customer_lookup';
    }

    public function handle(array $arguments): Response
    {
        return Response::text(json_encode([
            'customer' => 'USR_7f3a',
            'email' => SeededPii::EMAIL,
            'phone' => SeededPii::PHONE,
            'iban' => SeededPii::IBAN,
            'card' => SeededPii::CARD,
            'authorization' => SeededPii::BEARER_TOKEN,
            'note' => 'Contact ' . CanaryValues::EMAIL . ' ' . CanaryValues::TOKEN,
        ], JSON_THROW_ON_ERROR));
    }
}
