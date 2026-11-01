<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Classification\Ceiling;
use ContextGate\Contract\ContextContract;
use ContextGate\Pipeline\Budget;
use ContextGate\Pipeline\BudgetFlag;
use ContextGate\Source\RunbookTool;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;

/**
 * The firewall stages and what follows them: a refusal reason, a budget refusal, or the result with its notices.
 *
 * @internal May change without notice.
 */
final class FirewallStep
{
    public function __construct(private readonly FirewallRunner $firewall)
    {
    }

    /** @param array{0: list<array<string, mixed>>, 1: bool} $decoded */
    public function run(JsonRpcRequest $request, Caller $caller, string $name, Tool $tool, ContextContract $contract, Ceiling $ceiling, Budget $budget, array $decoded): JsonRpcResponse
    {
        try {
            $result = $this->firewall->firewall($caller, $name, $contract, $ceiling, $budget, $decoded[0]);
        } catch (Throwable $e) {
            $reference = ResponseBuilder::receipt($caller, $name, ReasonCode::HandlerFailed);
            Failure::note($e, $reference);
            Failure::release($reference);

            return ResponseBuilder::failed($request, $reference);
        }

        if ($result instanceof ReasonCode) {
            Failure::release($reference = ResponseBuilder::receipt($caller, $name, $result));

            return ResponseBuilder::forReason($request, $result, $reference, $name);
        }

        $budgetFlags = array_values(array_intersect(BudgetFlag::REFUSALS, $result->flags));

        if ($budgetFlags !== []) {
            $reason = BudgetFlag::reason($budgetFlags[0]);
            Failure::release($reference = ResponseBuilder::receipt($caller, $name, $reason, $result));

            return ResponseBuilder::forReason($request, $reason, $reference, $name);
        }

        $this->firewall->stage('receipt');
        $counts = $tool instanceof RunbookTool ? RunbookTool::statusCounts($result->rows) : null;
        Failure::release(ResponseBuilder::receipt($caller, $name, null, $result, statusCounts: $counts));
        $truncated = in_array(BudgetFlag::CALL, $result->flags, true);
        $flags = array_values(array_diff($result->flags, [BudgetFlag::CALL]));

        return ResponseBuilder::result($request, $result->rows, $decoded[1], $truncated, $flags);
    }
}
