<?php

declare(strict_types=1);

namespace ContextGate\Tests\Contract\Runbook;

use ContextGate\Tests\Fixtures\RoleFixture;
use ContextGate\Contracts\Runbook;
use ContextGate\Runbook\RunbookFingerprint;
use ContextGate\Runbook\RunbookRowsContract;
use ContextGate\Source\RunbookTool;
use ContextGate\Tests\Contract\GateCallHarness;
use Illuminate\Support\Facades\Gate;

/** Configures one runbook tool for a caller and records the receipt lines (spec 018 US2). */
trait RunbookToolHarness
{
    use GateCallHarness;

    /** @param class-string<Runbook> $runbookClass */
    private function configureRunbookTool(string $runbookClass, string $name = 'mixed', bool $enabled = true, ?string $fingerprint = null): void
    {
        Gate::define('runbook.allow', fn (): bool => true);
        app()->instance('context-gate.caller', 'alice');
        config(RoleFixture::stdio('alice', ['runbook.'.$name]));
        config(['context-gate.runbooks' => [$name => $runbookClass]]);
        config(['context-gate.tools' => ['runbook.'.$name => [
            'enabled' => $enabled,
            'class' => RunbookTool::class,
            'contract' => RunbookRowsContract::class,
            'gate' => 'runbook.allow',
            'fingerprint' => $fingerprint ?? $this->approvedFingerprint($runbookClass),
        ]]]);
        $this->recordLines();
    }

    /** @param class-string<Runbook> $runbookClass */
    private function approvedFingerprint(string $runbookClass): string
    {
        /** @var Runbook $runbook */
        $runbook = app($runbookClass);

        return RunbookFingerprint::of($runbook);
    }

    /** @return list<string> the tool names in tools/list */
    private function listedTools(): array
    {
        $raw = $this->send((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR))[0];

        return array_column(json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['result']['tools'], 'name');
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> the whole JSON-RPC answer to one tools/call, error or result
     */
    private function rawCall(string $name, array $arguments = []): array
    {
        $raw = $this->send((string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], JSON_THROW_ON_ERROR))[0];

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
