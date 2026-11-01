<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Classification\Ceiling;
use ContextGate\Pipeline\Budget;
use ContextGate\Pipeline\BudgetFlag;
use ContextGate\Policy\ToolPolicy;
use ContextGate\Source\SourceFailure;
use ContextGate\Support\GateCache;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;

final class GateCallTool implements Method
{
    /** ponytail: the session is the stdio process and its single caller; the session TTL is one hour, no config. */
    private const SESSION_TTL = 3600;

    /** Budget refusals; each flag value is the ReasonCode of its refusal. */
    private const BUDGET_REFUSALS = [BudgetFlag::SESSION, BudgetFlag::DAILY];

    public function __construct(private readonly FirewallRunner $firewall)
    {
    }

    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $caller = Caller::current();
        $name = $request->get('name');

        if (! is_string($name) || $name === '') {
            Receipt::write($caller->id, null, ReasonCode::InputInvalid, source: app()->bound(Preview::class) ? app(Preview::class)->source : null);

            throw new JsonRpcException('Missing or invalid parameter [name].', -32602, $request->id);
        }

        $registry = new ToolRegistry(config('context-gate.tools', []));
        $tool = $registry->find($name);

        // Hidden and unknown tools share one response (FR-005); the reason goes to the receipt only. The caller's name
        // is written nowhere for a name outside the registry: it is caller input, not a rule (F2).
        if ($tool === null || ! $registry->isVisible($name, $caller)) {
            ResponseBuilder::receipt($caller, $tool === null ? null : $name, $registry->isPinMismatch($name) ? ReasonCode::ToolPinMismatch : ReasonCode::PolicyHidden);

            throw new JsonRpcException('Tool not found.', -32602, $request->id);
        }
        if (($refused = ResponseBuilder::configRefusal($request, $caller, $name)) !== null) {
            return $refused;
        }

        // FR-021: the per-minute limit counts every call to a visible tool, including those refused below, so it is
        // checked before any pseudonym is resolved. Unknown and hidden tools are refused above, before the cache is read.
        $budget = $this->budget($caller);
        $retryAfter = $budget->admit();

        if ($retryAfter !== null) {
            ResponseBuilder::receipt($caller, $name, ReasonCode::RateLimited);

            return ResponseBuilder::refused($request, "Request refused. Retry after {$retryAfter} seconds.");
        }

        $arguments = (array) $request->get('arguments', []);

        // R8 and the contract rules: a refusal here comes before any pseudonym is resolved or the handler runs.
        Failure::clear();
        $contract = ToolRegistry::contractFor($name);

        if (($refusal = $this->firewall->argumentsRefusal($arguments, $name, $contract)) !== null) {
            Failure::release(ResponseBuilder::receipt($caller, $name, $refusal));

            return ResponseBuilder::refused($request);
        }

        // R5: a pseudonym argument is resolved on the server before validation. An unknown or expired one is
        // refused without saying whether a record exists (FR-010).
        $resolved = $contract === null ? $arguments : PseudonymArguments::resolve($arguments, $contract, $caller, $name);

        if ($resolved === null) {
            ResponseBuilder::receipt($caller, $name, ReasonCode::PseudonymUnknown);

            return ResponseBuilder::refused($request);
        }

        $arguments = $resolved;

        // FR-007: validation runs before the policy and the handler.
        $error = (new ArgumentValidator())->validate($tool, $arguments);

        if ($error !== null) {
            ResponseBuilder::receipt($caller, $name, ReasonCode::InputInvalid);

            throw new JsonRpcException($error, -32602, $request->id);
        }

        // FR-008: the policy sees only arguments that passed the schema. Refusal details stay out of the response.
        if (! app(ToolPolicy::class)->allows($name, $caller, $arguments)) {
            ResponseBuilder::receipt($caller, $name, ReasonCode::PolicyDenied);

            return ResponseBuilder::refused($request);
        }

        $ceiling = Caller::ceiling();

        // A configuration error is its own reason (F5). An enabled tool without a contract, and a secret ceiling
        // (nothing may be projected; constitution I and III), are refused before the handler runs (F4).
        if ($ceiling === null) {
            ResponseBuilder::receipt($caller, $name, ReasonCode::ConfigInvalid);

            return ResponseBuilder::refused($request);
        }

        if ($contract === null || $ceiling === Ceiling::Secret) {
            ResponseBuilder::receipt($caller, $name, ReasonCode::PolicyDenied);

            return ResponseBuilder::refused($request);
        }

        // FR-015: the exception message stays out of the response and the receipt; only the reference reaches the caller.
        try {
            $this->firewall->stage('fetch');
            $response = $tool->handle($arguments);
            $decoded = $this->firewall->decode((string) $response->content());
        } catch (SourceFailure $failure) {
            // A source refusal or failure keeps its own reason and step; the step is in the receipt, never the path.
            $reference = ResponseBuilder::receipt($caller, $name, $failure->reason, refusalStep: $failure->refusalStep);

            // An input refusal from a source is the same JSON-RPC error as any other input.invalid.
            if ($failure->reason === ReasonCode::InputInvalid) {
                throw new JsonRpcException("Invalid arguments for tool [{$name}].", -32602, $request->id);
            }

            return in_array($failure->reason, [ReasonCode::SourceRefused, ReasonCode::PolicyDenied], true)
                ? ResponseBuilder::refused($request)
                : ResponseBuilder::failed($request, $reference);
        } catch (Throwable $e) {
            $reference = ResponseBuilder::receipt($caller, $name, ReasonCode::HandlerFailed);
            Failure::note($e, $reference);

            return ResponseBuilder::failed($request, $reference);
        }

        try {
            $result = $this->firewall->firewall($caller, $name, $contract, $ceiling, $budget, $decoded[0]);
        } catch (Throwable $e) {
            $reference = ResponseBuilder::receipt($caller, $name, ReasonCode::HandlerFailed);
            Failure::note($e, $reference);

            return ResponseBuilder::failed($request, $reference);
        }

        if ($result instanceof ReasonCode) {
            $reference = ResponseBuilder::receipt($caller, $name, $result);
            Failure::release($reference);

            return in_array($result, [ReasonCode::CanaryHit, ReasonCode::TransformFailed, ReasonCode::PseudonymKeyFail], true) ? ResponseBuilder::failed($request, $reference) : ResponseBuilder::refused($request);
        }

        $budgetFlags = array_values(array_intersect(self::BUDGET_REFUSALS, $result->flags));

        if ($budgetFlags !== []) {
            // The budget code is the reason: each budget refusal is its own ReasonCode, named like its flag.
            Failure::release(ResponseBuilder::receipt($caller, $name, ReasonCode::from($budgetFlags[0]), $result));

            return ResponseBuilder::refused($request);
        }

        $this->firewall->stage('receipt');
        Failure::release(ResponseBuilder::receipt($caller, $name, null, $result));
        $truncated = in_array(BudgetFlag::CALL, $result->flags, true);
        $flags = array_values(array_diff($result->flags, [BudgetFlag::CALL]));
        $isList = $decoded[1];
        $rows = $result->rows;

        return ResponseBuilder::result($request, $rows, $isList, $truncated, $flags);
    }

    /**
     * A preview counts against an empty store, so running it never uses up the caller's limits. Its receipts still
     * go to the log, marked with their source.
     */
    private function budget(Caller $caller): Budget
    {
        if (app()->bound(Preview::class)) {
            $store = Cache::build(['driver' => 'array']);

            return new Budget($store, new RateLimiter($store), $caller->id, $caller->id, self::SESSION_TTL);
        }

        $store = GateCache::store();

        return new Budget($store, new RateLimiter($store), $caller->id, $caller->id, self::SESSION_TTL);
    }
}
