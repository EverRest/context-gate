<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Settings\ActiveSettings;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Transport\JsonRpcRequest;

/**
 * The decision a call would get, in the order GateCallTool decides it: visibility, the
 * settings check, then the arguments and the policy (ArgumentsStep). It never calls the handler and never admits the
 * per-minute budget. Fetch, projection and redaction are not simulated. Its receipts carry simulated: true.
 *
 * @internal May change without notice.
 */
final class Simulator
{
    /**
     * @param array<string, mixed> $arguments
     * @return ReasonCode|null the reason the call would stop, or null when it would reach the fetch stage
     */
    public static function decide(string $callerId, string $name, array $arguments): ?ReasonCode
    {
        $preview = new Preview();
        $preview->source = null;
        $preview->simulated = true;
        app()->instance(Preview::class, $preview);

        try {
            self::steps(new Caller($callerId, self::toolsOf($callerId)), $name, $arguments);
        } finally {
            app()->forgetInstance(Preview::class);
        }

        return $preview->reason;
    }

    /** @param array<string, mixed> $arguments */
    private static function steps(Caller $caller, string $name, array $arguments): void
    {
        if ($name === '') {
            ResponseBuilder::receipt($caller, null, ReasonCode::InputInvalid);

            return;
        }

        $request = new JsonRpcRequest(1, 'tools/call', ['name' => $name, 'arguments' => $arguments]);

        if (! app(ActiveSettings::class)->valid()) {
            ResponseBuilder::configRefusal($request, $caller, null);

            return;
        }

        $tools = app(ActiveSettings::class)->tools();

        try {
            [$name, $tool] = PolicyStep::visibleTool($request, $caller, new ToolRegistry($tools));
        } catch (JsonRpcException) {
            return;
        }

        if (ResponseBuilder::configRefusal($request, $caller, $name) !== null) {
            return;
        }

        $class = ToolRegistry::contractClassOf($tools[$name] ?? null);
        (new ArgumentsStep(app(FirewallRunner::class)))->run($request, $caller, $tool, $class === null ? null : app($class), $arguments);
    }

    /** @return list<string> */
    private static function toolsOf(string $callerId): array
    {
        $settings = app(ActiveSettings::class);

        return $settings->valid() ? ($settings->callers()[$callerId] ?? []) : [];
    }
}
