<?php

declare(strict_types=1);

namespace ContextGate\Server;

use Laravel\Mcp\Response;

/** @api Supported extension surface: covered by the compatibility policy in the README. */
abstract class Tool
{
    abstract public function name(): string;

    abstract public function description(): string;

    /** @return array<string, mixed> */
    abstract public function schema(): array;

    /**
     * MCP annotation hints shown to the client. The package scope is read-only, so the default is read-only,
     * non-destructive, idempotent and closed-world; clients may auto-approve such tools, so override this in any
     * subclass that does more (for example reaches the network).
     *
     * @return array<string, bool>
     */
    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    /**
     * The schema as the client sees it: an empty properties map is an object (JSON Schema needs one, PHP encodes
     * [] as an array) and extra arguments are forbidden, as ArgumentValidator enforces at the top level.
     *
     * @return array<string, mixed>
     */
    final public function inputSchema(): array
    {
        $schema = $this->schema();

        if (($schema['properties'] ?? null) === []) {
            $schema['properties'] = new \stdClass();
        }

        return $schema + ['additionalProperties' => false];
    }

    /** @param array<string, mixed> $arguments */
    abstract public function handle(array $arguments): Response;

    public function shouldRegister(): bool
    {
        return (new ToolRegistry(config('context-gate.tools', [])))->isVisible($this->name(), Caller::current());
    }
}
