<?php

declare(strict_types=1);

namespace ContextGate\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class StdioHandshakeTest extends TestCase
{
    public function test_initialize_answers_with_server_name_and_version(): void
    {
        $process = new Process(
            [PHP_BINARY, 'vendor/bin/testbench', 'mcp:start', 'context-gate'],
            dirname(__DIR__, 2),
        );
        $process->setInput(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'test', 'version' => '1'],
            ],
        ], JSON_THROW_ON_ERROR)."\n")->setTimeout(30)->run();

        $line = strtok($process->getOutput(), "\n") ?: '';
        $this->assertJson($line, 'stdout: '.$process->getOutput().' stderr: '.$process->getErrorOutput());

        $response = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $response['id']);
        $this->assertSame('context-gate', $response['result']['serverInfo']['name']);
        $this->assertNotSame('', $response['result']['serverInfo']['version']);
        $this->assertArrayHasKey('capabilities', $response['result']);
    }
}
