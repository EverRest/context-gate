<?php

declare(strict_types=1);

namespace ContextGate\Contracts;

use ContextGate\Server\Caller;

/**
 * Decides whether a caller may run a tool with the given validated arguments.
 *
 * Contract: deny by default. Anything not explicitly allowed (unknown tool, missing rule, error) returns false.
 *
 * @api Supported extension surface: bind your own implementation instead of extending the concrete ToolPolicy.
 */
interface AuthorizesTools
{
    /** @param array<string, mixed> $arguments */
    public function allows(string $tool, Caller $caller, array $arguments): bool;
}
