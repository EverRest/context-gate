<?php

declare(strict_types=1);

namespace ContextGate\Server;

use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/** FR-004: lists only the tools the caller may see; hidden, disabled and pin-mismatched tools are absent. */
final class GateListTools implements Method
{
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $registry = new ToolRegistry(config('context-gate.tools', []));

        $tools = array_map(static fn (Tool $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'inputSchema' => $tool->schema(),
            // Static and package-owned (every tool is read-only), so not part of the fingerprint.
            'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
        ], $registry->visibleTo(Caller::current()));

        return JsonRpcResponse::result($request->id, ['tools' => $tools]);
    }
}
