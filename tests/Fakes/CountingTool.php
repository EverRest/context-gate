<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fakes;

use ContextGate\Tests\Fixtures\EchoTool;
use Laravel\Mcp\Response;

/** Named like the probe tool, with a call counter: a simulation must leave the counter at 0. */
final class CountingTool extends EchoTool
{
    public static int $calls = 0;

    public function name(): string
    {
        return 'probe';
    }

    public function handle(array $arguments): Response
    {
        self::$calls++;

        return Response::text('[]');
    }
}
