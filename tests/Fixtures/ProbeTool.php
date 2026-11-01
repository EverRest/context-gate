<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures;

use Laravel\Mcp\Response;

final class ProbeTool extends EchoTool
{
    public static string $output = '[]';

    public static bool $called = false;

    public static ?\Throwable $throw = null;

    public function name(): string
    {
        return 'probe';
    }

    public function handle(array $arguments): Response
    {
        self::$called = true;

        if (self::$throw !== null) {
            throw self::$throw;
        }

        return Response::text(self::$output);
    }
}
