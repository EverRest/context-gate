<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit;

use ContextGate\Tests\TestCase;
use Monolog\Handler\StreamHandler;

class ContextGateServiceProviderTest extends TestCase
{
    public function test_merges_package_config_with_closed_defaults(): void
    {
        $this->assertFalse(config('context-gate.enabled'));
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

    public function test_binds_caller_id_from_current_user(): void
    {
        $this->assertSame(get_current_user(), app('context-gate.caller'));
    }
}
