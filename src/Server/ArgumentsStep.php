<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Classification\Ceiling;
use ContextGate\Contract\ContextContract;
use ContextGate\Contracts\AuthorizesTools;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * Everything decided from the arguments alone, in this order: canary and contract rules (R8), the tool's input schema
 * on the arguments exactly as the client sent them (FR-007), pseudonym resolution (R5, FR-010), the policy (FR-008),
 * then the ceiling and contract checks. A resolved id is never validated against the schema: it is an internal value.
 *
 * @internal May change without notice.
 */
final class ArgumentsStep
{
    public function __construct(private readonly FirewallRunner $firewall)
    {
    }

    /**
     * @param array<string, mixed> $arguments
     * @return JsonRpcResponse|array{arguments: array<string, mixed>, contract: ContextContract, ceiling: Ceiling}
     */
    public function run(JsonRpcRequest $request, Caller $caller, Tool $tool, ?ContextContract $contract, array $arguments): JsonRpcResponse|array
    {
        $name = $tool->name();
        Failure::clear();

        if (($refusal = $this->firewall->argumentsRefusal($arguments, $name, $contract)) !== null) {
            Failure::release(ResponseBuilder::receipt($caller, $name, $refusal));

            return ResponseBuilder::refused($request);
        }

        $error = (new ArgumentValidator())->validate($tool, $arguments);

        if ($error !== null) {
            ResponseBuilder::receipt($caller, $name, ReasonCode::InputInvalid);

            // An input error is a tool error, not a protocol error: the client's model sees it and can correct itself.
            return ResponseBuilder::refused($request, "Invalid arguments: {$error}");
        }

        // An unknown or expired pseudonym is refused without saying whether a record exists (FR-010).
        $resolved = $contract === null ? $arguments : PseudonymArguments::resolve($arguments, $contract, $caller, $name);

        if ($resolved === null) {
            ResponseBuilder::receipt($caller, $name, ReasonCode::PseudonymUnknown);

            return ResponseBuilder::refused($request);
        }

        if (! app(AuthorizesTools::class)->allows($name, $caller, $resolved)) {
            ResponseBuilder::receipt($caller, $name, ReasonCode::PolicyDenied);

            return ResponseBuilder::refused($request);
        }

        $ceiling = Caller::ceiling();

        // A configuration error is its own reason (F5). An enabled tool without a contract, and a secret ceiling
        // (nothing may be projected; constitution I and III), are refused before the handler runs (F4).
        // The secret check here is a second guard: config validation refuses a secret ceiling first, and a token
        // never carries one (GateTokenAuth refuses such a token before this step runs).
        if ($ceiling === null || $contract === null || $ceiling === Ceiling::Secret) {
            ResponseBuilder::receipt($caller, $name, $ceiling === null ? ReasonCode::ConfigInvalid : ReasonCode::PolicyDenied);

            return ResponseBuilder::refused($request);
        }

        return ['arguments' => $resolved, 'contract' => $contract, 'ceiling' => $ceiling];
    }
}
