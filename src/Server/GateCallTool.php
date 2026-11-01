<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Classification\Ceiling;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\ContractValidator;
use ContextGate\Contract\Field;
use ContextGate\Pipeline\Budget;
use ContextGate\Policy\ToolPolicy;
use ContextGate\Source\SourceFailure;
use ContextGate\Privacy\Pseudonymizer;
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
    private const BUDGET_REFUSALS = ['budget.session', 'budget.daily'];

    public function __construct(private readonly FirewallRunner $firewall)
    {
    }

    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $caller = Caller::current();
        $name = $request->get('name');

        if (! is_string($name) || $name === '') {
            Receipt::write($caller->id, null, ReasonCode::InputInvalid);

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
        if (($refusal = $this->firewall->argumentsRefusal($arguments, $name, $this->contractFor($name))) !== null) {
            ResponseBuilder::receipt($caller, $name, $refusal);

            return ResponseBuilder::refused($request);
        }

        // R5: a pseudonym argument is resolved on the server before validation. An unknown or expired one is
        // refused without saying whether a record exists (FR-010).
        foreach ($this->pseudonymFields($name) as $column => $field) {
            if (! array_key_exists($column, $arguments)) {
                continue;
            }

            // A present but non-string value cannot be a pseudonym: it is refused like an unknown one, not passed on.
            $record = is_string($arguments[$column]) ? $this->resolvePseudonym($field, $caller, $name, $arguments[$column]) : null;

            if ($record === null) {
                ResponseBuilder::receipt($caller, $name, ReasonCode::PseudonymUnknown);

                return ResponseBuilder::refused($request);
            }

            $arguments[$column] = $record['id'];
        }

        // FR-007: validation runs before the policy and the handler.
        $error = (new ArgumentValidator())->validate($tool, $arguments);

        if ($error !== null) {
            ResponseBuilder::receipt($caller, $name, ReasonCode::InputInvalid);

            throw new JsonRpcException($error, -32602, $request->id);
        }

        // FR-008: the policy sees only arguments that passed the schema. Refusal details stay out of the response.
        if (! (new ToolPolicy())->allows($name, $caller, $arguments)) {
            ResponseBuilder::receipt($caller, $name, ReasonCode::PolicyDenied);

            return ResponseBuilder::refused($request);
        }

        $contract = $this->contractFor($name);
        $configuredCeiling = config('context-gate.firewall.ceiling', Ceiling::Internal->value);
        $ceiling = is_string($configuredCeiling) ? Ceiling::tryFrom($configuredCeiling) : null;

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
        } catch (Throwable) {
            $reference = ResponseBuilder::receipt($caller, $name, ReasonCode::HandlerFailed);

            return ResponseBuilder::failed($request, $reference);
        }

        try {
            $result = $this->firewall->firewall($caller, $name, $contract, $ceiling, $budget, $decoded[0]);
        } catch (Throwable) {
            $reference = ResponseBuilder::receipt($caller, $name, ReasonCode::HandlerFailed);

            return ResponseBuilder::failed($request, $reference);
        }

        if ($result instanceof ReasonCode) {
            $reference = ResponseBuilder::receipt($caller, $name, $result);

            return in_array($result, [ReasonCode::CanaryHit, ReasonCode::TransformFailed, ReasonCode::PseudonymKeyFail], true) ? ResponseBuilder::failed($request, $reference) : ResponseBuilder::refused($request);
        }

        $budgetFlags = array_values(array_intersect(self::BUDGET_REFUSALS, $result->flags));

        if ($budgetFlags !== []) {
            // The budget code is the reason: each budget refusal is its own ReasonCode, named like its flag.
            ResponseBuilder::receipt($caller, $name, ReasonCode::from($budgetFlags[0]), $result);

            return ResponseBuilder::refused($request);
        }

        $this->firewall->stage('receipt');
        ResponseBuilder::receipt($caller, $name, null, $result);
        $truncated = in_array('budget.call', $result->flags, true);
        $flags = array_values(array_diff($result->flags, ['budget.call']));
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

        return new Budget(Cache::store(), app(RateLimiter::class), $caller->id, $caller->id, self::SESSION_TTL);
    }

    /** @return array<string, Field> the contract's pseudonym-form fields, by column */
    private function pseudonymFields(string $name): array
    {
        $contract = $this->contractFor($name);

        if ($contract === null) {
            return [];
        }

        return array_filter($contract->fields(), static fn (Field $field): bool => $field->form === Field::PSEUDONYM);
    }

    /** @return array{table: string, id: string}|null null when unknown, expired or unreadable */
    private function resolvePseudonym(Field $field, Caller $caller, string $name, string $value): ?array
    {
        $contract = $this->contractFor($name);
        $prefix = $field->options['prefix'] ?? null;

        // A pseudonym must carry this column's prefix and belong to this contract's table; otherwise it is refused.
        if ($contract === null || ! is_string($prefix) || ! str_starts_with($value, $prefix.'_')) {
            return null;
        }

        try {
            $record = (new Pseudonymizer())->resolve($value, FirewallRunner::scopeKey($field, $name, $caller), $caller->id);
        } catch (Throwable) {
            // Fail closed: an unreadable scope refuses exactly like an unknown pseudonym.
            return null;
        }

        return $record !== null && $record['table'] === (new ContractValidator())->tableOf($contract) ? $record : null;
    }

    private function contractFor(string $name): ?ContextContract
    {
        /** @var array<string, array<string, mixed>> $tools */
        $tools = config('context-gate.tools', []);
        $class = $tools[$name]['contract'] ?? null;

        if (! is_string($class) || ! is_a($class, ContextContract::class, true)) {
            return null;
        }

        return new $class();
    }
}
