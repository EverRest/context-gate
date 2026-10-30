<?php

declare(strict_types=1);

namespace ContextGate\Server;

use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

final class GateCallTool implements Method
{
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $caller = Caller::current();
        $name = (string) $request->get('name');
        $registry = new ToolRegistry(config('context-gate.tools', []));

        // Hidden and unknown tools share one response (FR-005); the reason goes to the receipt only.
        if (! $registry->isVisible($name, $caller)) {
            Receipt::write($caller->id, $name, ReasonCode::PolicyHidden);

            throw new JsonRpcException("Tool [{$name}] not found.", -32602, $request->id);
        }

        // ponytail: visible path has no argument checks, policy or receipt yet; T034 and US1 add them.
        $response = $registry->find($name)?->handle($request->get('arguments', []));

        return JsonRpcResponse::result($request->id, [
            'content' => [['type' => 'text', 'text' => (string) $response?->content()]],
            'isError' => false,
        ]);
    }
}
