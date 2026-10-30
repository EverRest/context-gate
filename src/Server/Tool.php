<?php

declare(strict_types=1);

namespace ContextGate\Server;

use Laravel\Mcp\Response;

abstract class Tool
{
    abstract public function name(): string;

    abstract public function description(): string;

    /** @return array<string, mixed> */
    abstract public function schema(): array;

    /** @param array<string, mixed> $arguments */
    abstract public function handle(array $arguments): Response;

    public function shouldRegister(): bool
    {
        return (new ToolRegistry(config('context-gate.tools', [])))->isVisible($this->name(), Caller::current());
    }
}
