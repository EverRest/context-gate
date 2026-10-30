<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use ContextGate\Server\Tool;
use Laravel\Mcp\Response;

class EchoTool extends Tool
{
    public function name(): string
    {
        return 'echo';
    }

    public function description(): string
    {
        return 'Echoes the text argument.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string']],
            'required' => ['text'],
        ];
    }

    public function handle(array $arguments): Response
    {
        return Response::text($arguments['text']);
    }
}
