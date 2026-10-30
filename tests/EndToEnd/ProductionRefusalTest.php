<?php

declare(strict_types=1);

namespace ContextGate\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ProductionRefusalTest extends TestCase
{
    public function test_stdio_start_refuses_production_with_stderr_reason_and_empty_stdout(): void
    {
        $process = new Process(
            [PHP_BINARY, 'vendor/bin/testbench', 'mcp:start', 'context-gate'],
            dirname(__DIR__, 2),
            ['APP_ENV' => 'production'],
        );
        $process->setInput(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
        ], JSON_THROW_ON_ERROR)."\n")->setTimeout(30)->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertSame('', $process->getOutput(), 'stdout must stay empty');
        $this->assertStringContainsString('production', $process->getErrorOutput());
    }
}
