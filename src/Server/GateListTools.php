<?php

declare(strict_types=1);

namespace ContextGate\Server;

use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;

/**
 * Lists only the tools the caller may see; hidden, disabled and pin-mismatched tools are absent.
 *
 * @internal May change without notice.
 */
final class GateListTools implements Method
{
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        // context-gate.enabled is the master switch: false lists nothing.
        if (config('context-gate.enabled') !== true) {
            return JsonRpcResponse::result($request->id, ['tools' => []]);
        }

        try {
            return $this->list($request);
        } catch (Throwable $e) {
            // The exception must not reach the host's report() or, with app.debug on, escape the server.
            ResponseBuilder::crashed($e);

            return JsonRpcResponse::error($request->id, -32603, 'Internal error.');
        }
    }

    private function list(JsonRpcRequest $request): JsonRpcResponse
    {
        $registry = new ToolRegistry(config('context-gate.tools', []));

        $tools = array_map(static fn (Tool $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'inputSchema' => $tool->inputSchema(),
            'annotations' => $tool->annotations(),
        ], $registry->visibleTo(Caller::current()));

        return JsonRpcResponse::result($request->id, ['tools' => $tools]);
    }
}
