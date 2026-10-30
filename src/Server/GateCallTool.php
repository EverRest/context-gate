<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Policy\ToolPolicy;
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
        $tool = $registry->find($name);

        // Hidden and unknown tools share one response (FR-005); the reason goes to the receipt only.
        if ($tool === null || ! $registry->isVisible($name, $caller)) {
            Receipt::write($caller->id, $name, $registry->isPinMismatch($name) ? ReasonCode::ToolPinMismatch : ReasonCode::PolicyHidden);

            throw new JsonRpcException("Tool [{$name}] not found.", -32602, $request->id);
        }

        $arguments = (array) $request->get('arguments', []);

        // FR-007: validation runs before the policy and the handler.
        $error = (new ArgumentValidator())->validate($tool, $arguments);

        if ($error !== null) {
            Receipt::write($caller->id, $name, ReasonCode::InputInvalid);

            throw new JsonRpcException($error, -32602, $request->id);
        }

        // FR-008: the policy sees only arguments that passed the schema. Refusal details stay out of the response.
        if (! (new ToolPolicy())->allows($name, $caller, $arguments)) {
            Receipt::write($caller->id, $name, ReasonCode::PolicyDenied);

            return JsonRpcResponse::result($request->id, [
                'content' => [['type' => 'text', 'text' => 'Request refused.']],
                'isError' => true,
            ]);
        }

        $response = $tool->handle($arguments);

        return JsonRpcResponse::result($request->id, [
            'content' => [['type' => 'text', 'text' => (string) $response->content()]],
            'isError' => false,
        ]);
    }
}
