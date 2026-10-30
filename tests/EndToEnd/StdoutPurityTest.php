<?php

declare(strict_types=1);

namespace ContextGate\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class StdoutPurityTest extends TestCase
{
    public function test_every_stdout_line_is_a_json_rpc_message_during_a_full_session(): void
    {
        $process = new Process(
            [PHP_BINARY, 'vendor/bin/testbench', 'mcp:start', 'context-gate'],
            dirname(__DIR__, 2),
        );
        $process->setInput(implode("\n", [
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']]]),
            json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']),
            json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']),
            json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'missing', 'arguments' => []]]),
        ])."\n")->setTimeout(30)->run();

        $lines = array_values(array_filter(explode("\n", $process->getOutput()), fn (string $line): bool => $line !== ''));

        $this->assertCount(3, $lines, 'stdout: '.$process->getOutput().' stderr: '.$process->getErrorOutput());
        foreach ($lines as $line) {
            $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('2.0', $message['jsonrpc']);
        }
    }
}
