<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use ContextGate\Server\Tool;
use Laravel\Mcp\Response;
use RuntimeException;

/** A tool whose constructor fails, as one does when it needs a binding the application has not registered yet. */
final class UnbuildableTool extends Tool
{
    public function __construct()
    {
        throw new RuntimeException('needs a binding the host registers later');
    }

    public function name(): string
    {
        return 'unbuildable';
    }

    public function description(): string
    {
        return 'Never built.';
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]];
    }

    /** @param array<string, mixed> $arguments */
    public function handle(array $arguments): Response
    {
        return Response::text('[]');
    }
}
