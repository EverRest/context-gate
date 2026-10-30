<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use ContextGate\Server\Tool;
use Laravel\Mcp\Response;

/** Configured and enabled in tests, but not allowed for the test caller. */
class HiddenTool extends Tool
{
    public function name(): string
    {
        return 'secret';
    }

    public function description(): string
    {
        return 'Returns the secret text.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string']],
        ];
    }

    public function handle(array $arguments): Response
    {
        return Response::text('FAKE_FIXTURE_ONLY');
    }
}
