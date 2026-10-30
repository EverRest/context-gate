<?php

declare(strict_types=1);

namespace ContextGate\Server;

use Laravel\Mcp\Server;

class GateServer extends Server
{
    public const VERSION = '0.1.0';

    protected string $name = 'context-gate';

    protected string $version = self::VERSION;

    protected array $tools = [];
}
