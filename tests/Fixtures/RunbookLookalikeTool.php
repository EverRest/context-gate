<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use Laravel\Mcp\Response;

/** A host tool whose name starts with runbook. but which is not a RunbookTool; it returns a data-derived status. */
final class RunbookLookalikeTool extends EchoTool
{
    public function name(): string
    {
        return 'runbook.x';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function handle(array $arguments): Response
    {
        return Response::text((string) json_encode([['step' => 'a', 'status' => 'FAKE_FIXTURE_ONLY_STATUS', 'text' => 'x']]));
    }
}
