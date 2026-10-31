<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Server;

use ContextGate\Server\GateServer;
use ContextGate\Tests\Fixtures\CanaryValues;
use ContextGate\Tests\Fixtures\EchoTool;
use ContextGate\Tests\Fixtures\SeededPii;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\Transport\FakeTransporter;

/** FR-024: a receipt holds metadata and hashes only; no seeded personal value or canary may appear in one. */
class ReceiptNoValuesTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    public function test_no_seeded_value_or_canary_appears_in_any_receipt_line(): void
    {
        Gate::define('echo.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(['context-gate.callers.alice.tools' => ['echo']]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'echo' => ['enabled' => true, 'class' => EchoTool::class, 'gate' => 'echo.allow', 'fingerprint' => hash('sha256', (new EchoTool())->description())],
        ]]);

        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info', 'error')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });

        $this->send(
            $this->toolCall(1, ['text' => SeededPii::EMAIL]),
            $this->toolCall(2, ['text' => SeededPii::BEARER_TOKEN, 'extra' => SeededPii::CARD]),
            $this->toolCall(3, ['text' => CanaryValues::TOKEN]),
            $this->toolCall(4, ['text' => SeededPii::PHONE . ' ' . SeededPii::IBAN]),
        );

        $this->assertCount(4, $this->lines);

        foreach ($this->seededValues() as $label => $value) {
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

    /** @param array<string, mixed> $arguments */
    private function toolCall(int $id, array $arguments): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/call',
            'params' => ['name' => 'echo', 'arguments' => $arguments],
        ], JSON_THROW_ON_ERROR);
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
