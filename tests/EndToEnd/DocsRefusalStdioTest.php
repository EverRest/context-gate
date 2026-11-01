<?php

declare(strict_types=1);

namespace ContextGate\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** spec 003 FR-017 and SC-004 over stdio: a refused docs call says nothing about the path, and stdout stays protocol. */
class DocsRefusalStdioTest extends TestCase
{
    /**
     * Starts the stdio server with the docs source enabled and the env file listed, which the denylist still refuses.
     * The bootstrap runs in the child process only; the package config is not edited.
     */
    private const BOOT = <<<'PHP'
        require 'vendor/autoload.php';
        use ContextGate\Server\SourceTools;
        use Illuminate\Support\Facades\Gate;
        use Symfony\Component\Console\Input\ArgvInput;
        use Symfony\Component\Console\Output\ConsoleOutput;
        $user = posix_getpwuid(posix_geteuid())['name'];
        $tools = SourceTools::entries();
        // The tracked fixture application holds the .env the test asks for, so the denylist (not a missing file) refuses it.
        $app = Orchestra\Testbench\Foundation\Application::create(getcwd().'/tests/Fixtures/app');
        // Register the providers explicitly: the child must not depend on a package discovery cache.
        $app->register(Laravel\Mcp\Server\McpServiceProvider::class);
        $app->register(ContextGate\ContextGateServiceProvider::class);
        $app->booted(static function ($app) use ($user, $tools): void {
            $app['config']->set('cache.default', 'array');
            $app['config']->set('context-gate.enabled', true);
            $app['config']->set('context-gate.callers', [$user => ['tools' => ['docs.read']]]);
            $app['config']->set('context-gate.tools', ['docs.read' => ['enabled' => true, 'gate' => 'source.allow'] + $tools['docs.read']]);
            $app['config']->set('context-gate.sources.docs.paths', ['.env']);
            Gate::define('source.allow', static fn (): bool => true);
        });
        exit($app->make(Illuminate\Contracts\Console\Kernel::class)->handle(new ArgvInput(['artisan', 'mcp:start', 'context-gate']), new ConsoleOutput()));
        PHP;

    public function test_refused_docs_call_returns_no_path_and_stdout_stays_json_rpc(): void
    {
        $process = new Process([PHP_BINARY, '-r', self::BOOT], dirname(__DIR__, 2));
        $process->setInput(implode("\n", [
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']]], JSON_THROW_ON_ERROR),
            json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR),
            json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'docs.read', 'arguments' => ['path' => '.env']]], JSON_THROW_ON_ERROR),
        ])."\n")->setTimeout(60)->run();

        $context = 'stdout: '.$process->getOutput().' stderr: '.$process->getErrorOutput();
        $lines = array_values(array_filter(explode("\n", $process->getOutput()), static fn (string $line): bool => $line !== ''));

        $this->assertCount(2, $lines, $context);
        foreach ($lines as $line) {
            $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('2.0', $message['jsonrpc'], $context);
        }

        $refusal = json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR)['result'];
        $this->assertTrue($refusal['isError'], $context);
        $this->assertSame('Request refused.', $refusal['content'][0]['text'], $context);
        $this->assertStringNotContainsString('.env', $process->getOutput(), $context);
        $this->assertStringContainsString('"refusal_step":"denylist"', $process->getErrorOutput(), $context);
    }
}
