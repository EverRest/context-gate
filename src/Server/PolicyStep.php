<?php

declare(strict_types=1);

namespace ContextGate\Server;

use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Transport\JsonRpcRequest;

/**
 * The first step of a tool call: the name must be a string, and the tool must be enabled, pinned to its approved
 * fingerprint and visible to the caller. Hidden and unknown tools share one response (FR-005); the reason goes to the
 * receipt only. The caller's name is written nowhere for a name outside the registry: it is caller input, not a rule
 * (F2). enabled=false answers exactly like an unknown tool: nothing tells the client the gate is off.
 *
 * @internal May change without notice.
 */
final class PolicyStep
{
    /**
     * @return array{string, Tool}
     *
     * @throws JsonRpcException when the name is missing, or the tool is not found for this caller
     */
    public static function visibleTool(JsonRpcRequest $request, Caller $caller, ToolRegistry $registry): array
    {
        $name = $request->get('name');

        if (! is_string($name) || $name === '') {
            Receipt::write($caller->id, null, ReasonCode::InputInvalid, source: app()->bound(Preview::class) ? app(Preview::class)->source : null);

            throw new JsonRpcException('Missing or invalid parameter [name].', -32602, $request->id);
        }

        $tool = $registry->find($name);
        $off = config('context-gate.enabled') !== true;

        if ($off || $tool === null || ! $registry->isVisible($name, $caller)) {
            ResponseBuilder::receipt($caller, $off || $tool === null ? null : $name, ! $off && $registry->isPinMismatch($name) ? ReasonCode::ToolPinMismatch : ReasonCode::PolicyHidden);

            throw new JsonRpcException('Tool not found.', -32602, $request->id);
        }

        return [$name, $tool];
    }
}
