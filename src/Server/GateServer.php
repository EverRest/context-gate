<?php

declare(strict_types=1);

namespace ContextGate\Server;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;

class GateServer extends Server
{
    public const VERSION = '0.1.0';

    protected string $name = 'context-gate';

    protected string $version = self::VERSION;

    protected array $tools = [];

    public function __construct(Transport $transport)
    {
        parent::__construct($transport);

        $this->addMethod('tools/list', GateListTools::class);
        $this->addMethod('tools/call', GateCallTool::class);
    }

    protected function boot(): void
    {
        if (app()->environment('production')) {
            fwrite(STDERR, "context-gate: refusing to start over stdio in production; use authenticated HTTP.\n");

            exit(1);
        }

        // FR-016: an invalid tool entry stops the server here, not on the first call.
        new ToolRegistry(config('context-gate.tools', []));
    }
}
