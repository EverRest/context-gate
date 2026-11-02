<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract;

use ContextGate\Tests\Fixtures\RoleFixture;
use ContextGate\Contract\ContextContract;
use ContextGate\Server\Fingerprint;
use ContextGate\Server\GateServer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use ContextGate\Tests\Fixtures\ProbeTool;

/** Sends JSON-RPC calls through GateServer and records the receipt lines written to the log channel. */
trait GateCallHarness
{
    /** @var list<string> */
    private array $lines = [];

    private function recordLines(): void
    {
        Log::shouldReceive('channel')->with('context-gate')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function (string $line): void {
            $this->lines[] = $line;
        });
    }

    /**
     * The last receipt line: failure notes (reference, exception class, file, line) share the channel but have no decision.
     *
     * @return array<string, mixed>
     */
    private function lastReceipt(): array
    {
        $receipts = array_values(array_filter(
            array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $this->lines),
            static fn (array $record): bool => array_key_exists('decision', $record),
        ));

        return $receipts[count($receipts) - 1];
    }

    /**
     * @param class-string<ContextContract> $contract
     * @param array<string, array<string, string>> $classes
     */
    private function configureTool(string $contract, array $classes): void
    {
        Gate::define('echo.allow', fn (): bool => true);
        ProbeTool::$called = false;
        app()->instance('context-gate.caller', 'alice');
        config(RoleFixture::stdio('alice', ['probe']));
        config(['context-gate.classification' => $classes]);
        config(['context-gate.enabled' => true, 'context-gate.tools' => [
            'probe' => [
                'enabled' => true,
                'class' => ProbeTool::class,
                'contract' => $contract,
                'gate' => 'echo.allow',
                'fingerprint' => Fingerprint::of(new ProbeTool()),
            ],
        ]]);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> the result object of one tools/call
     */
    private function callTool(string $name, array $arguments): array
    {
        $raw = $this->send((string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], JSON_THROW_ON_ERROR))[0];

        /** @var array<string, mixed> $result */
        $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['result'];

        return $result;
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
