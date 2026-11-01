<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit;

use ContextGate\ContextGateServiceProvider;
use ContextGate\Tests\TestCase;
use Monolog\Handler\StreamHandler;

class ContextGateServiceProviderTest extends TestCase
{
    public function test_merges_package_config_with_closed_defaults(): void
    {
        // The shipped default: TestCase turns the gate on for the call-path tests.
        $this->assertFalse((require dirname(__DIR__, 2).'/config/context-gate.php')['enabled']);
        $this->assertSame([], config('context-gate.tools'));
        $this->assertSame('context-gate', config('context-gate.channel'));
    }

    public function test_registers_context_gate_channel_to_stderr_only(): void
    {
        $channel = config('logging.channels.context-gate');

        $this->assertSame('monolog', $channel['driver']);
        $this->assertSame(StreamHandler::class, $channel['handler']);
        $this->assertSame(['stream' => 'php://stderr'], $channel['with']);
    }

    public function test_binds_caller_id_from_effective_os_user(): void
    {
        if (! function_exists('posix_geteuid') || ! function_exists('posix_getpwuid')) {
            $this->markTestSkipped('POSIX functions are unavailable; the effective OS user cannot be resolved on this platform.');
        }

        $passwd = posix_getpwuid(posix_geteuid());

        $this->assertSame(is_array($passwd) ? $passwd['name'] : 'unknown', app('context-gate.caller'));
    }

    public function test_provider_never_uses_script_owner(): void
    {
        $file = (new \ReflectionClass(ContextGateServiceProvider::class))->getFileName();

        $source = is_string($file) ? file_get_contents($file) : false;

        if (! is_string($source)) {
            $this->fail('Provider source could not be read.');
        }

        $this->assertStringNotContainsString('get_current_user', $source);
    }
}
