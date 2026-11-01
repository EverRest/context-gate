<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Readiness;

use ContextGate\ContextGate;
use ContextGate\Server\GateServer;
use PHPUnit\Framework\TestCase;

class VersionTest extends TestCase
{
    public function test_gate_server_version_is_the_package_version(): void
    {
        $this->assertSame(ContextGate::VERSION, GateServer::VERSION);
    }
}
