<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Classification\Ceiling;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\ContractValidator;
use ContextGate\Contract\Field;
use ContextGate\Pipeline\Budget;
use ContextGate\Pipeline\Payload;
use ContextGate\Policy\ToolPolicy;
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

        // Hidden and unknown tools share one response (FR-005); the reason goes to the receipt only.
        if ($tool === null || ! $registry->isVisible($name, $caller)) {
            $this->receipt($caller, $name, $registry->isPinMismatch($name) ? ReasonCode::ToolPinMismatch : ReasonCode::PolicyHidden);

            throw new JsonRpcException("Tool [{$name}] not found.", -32602, $request->id);
        }

        // FR-021: the per-minute limit counts every call to a visible tool, including those refused below, so it is
        // checked before any pseudonym is resolved. Unknown and hidden tools are refused above, before the cache is read.
        $budget = new Budget(Cache::store(), app(RateLimiter::class), $caller->id, $caller->id, self::SESSION_TTL);
        $retryAfter = $budget->admit();

        if ($retryAfter !== null) {
            $this->receipt($caller, $name, ReasonCode::RateLimited);

            return $this->refused($request, "Request refused. Retry after {$retryAfter} seconds.");
        }

        $arguments = (array) $request->get('arguments', []);

        // R8 and the contract rules: a refusal here comes before any pseudonym is resolved or the handler runs.
        if (($refusal = $this->firewall->argumentsRefusal($arguments, $name, $this->contractFor($name))) !== null) {
            $this->receipt($caller, $name, $refusal);

            return $this->refused($request);
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
                $this->receipt($caller, $name, ReasonCode::PseudonymUnknown);

                return $this->refused($request);
            }

            $arguments[$column] = $record['id'];
        }

        // FR-007: validation runs before the policy and the handler.
        $error = (new ArgumentValidator())->validate($tool, $arguments);

        if ($error !== null) {
            $this->receipt($caller, $name, ReasonCode::InputInvalid);

            throw new JsonRpcException($error, -32602, $request->id);
        }

        // FR-008: the policy sees only arguments that passed the schema. Refusal details stay out of the response.
        if (! (new ToolPolicy())->allows($name, $caller, $arguments)) {
            $this->receipt($caller, $name, ReasonCode::PolicyDenied);

            return $this->refused($request);
        }

        $contract = $this->contractFor($name);
        $ceiling = Ceiling::from((string) config('context-gate.firewall.ceiling', Ceiling::Internal->value));
        // FR-015: the exception message stays out of the response and the receipt; only the reference reaches the caller.
        try {
            $this->firewall->stage('fetch');
            $response = $tool->handle($arguments);
            // A secret ceiling or a tool without a contract leaves nothing to project (constitution I and III).
            $decoded = $contract === null || $ceiling === Ceiling::Secret ? null : $this->firewall->decode((string) $response->content());
        } catch (Throwable) {
            $reference = $this->receipt($caller, $name, ReasonCode::HandlerFailed);

            return $this->failed($request, $reference);
        }

        if ($contract === null || $decoded === null) {
            $this->receipt($caller, $name, ReasonCode::PolicyDenied);

            return $this->refused($request);
        }

        try {
            $result = $this->firewall->firewall($caller, $name, $contract, $ceiling, $budget, $decoded[0]);
        } catch (Throwable) {
            $reference = $this->receipt($caller, $name, ReasonCode::HandlerFailed);

            return $this->failed($request, $reference);
        }

        if ($result instanceof ReasonCode) {
            $reference = $this->receipt($caller, $name, $result);

            return $result === ReasonCode::CanaryHit ? $this->failed($request, $reference) : $this->refused($request);
        }

        $budgetFlags = array_values(array_intersect(self::BUDGET_REFUSALS, $result->flags));

        if ($budgetFlags !== []) {
            // The budget code is the reason: each budget refusal is its own ReasonCode, named like its flag.
            $this->receipt($caller, $name, ReasonCode::from($budgetFlags[0]), $result);

            return $this->refused($request);
        }

        $this->firewall->stage('receipt');
        $this->receipt($caller, $name, null, $result);
        $truncated = in_array('budget.call', $result->flags, true);
        $flags = array_values(array_diff($result->flags, ['budget.call']));
        $isList = $decoded[1];
        $rows = $result->rows;

        return JsonRpcResponse::result($request->id, [
            'content' => [['type' => 'text', 'text' => (string) json_encode($isList ? $rows : ($rows[0] ?? []), JSON_THROW_ON_ERROR)]],
            'isError' => false,
            'structuredContent' => ['rows' => count($rows), 'truncated' => $truncated, 'flags' => $flags],
        ]);
    }

    private function receipt(Caller $caller, string $tool, ?ReasonCode $reason = null, ?Payload $payload = null): string
    {
        $rules = app()->bound('context-gate.rules') ? app('context-gate.rules') : [];

        /** @var array<string, string> $rules */
        if ($payload === null) {
            return Receipt::write($caller->id, $tool, $reason, $rules);
        }

        return Receipt::write(
            $caller->id,
            $tool,
            $reason,
            $rules,
            $payload->dropped,
            in_array('budget.call', $payload->flags, true),
            array_values(array_diff($payload->flags, ['budget.call'])),
        );
    }

    private function refused(JsonRpcRequest $request, ?string $text = null): JsonRpcResponse
    {
        return JsonRpcResponse::result($request->id, [
            'content' => [['type' => 'text', 'text' => $text ?? 'Request refused.']],
            'isError' => true,
        ]);
    }

    private function failed(JsonRpcRequest $request, string $reference): JsonRpcResponse
    {
        return JsonRpcResponse::result($request->id, [
            'content' => [['type' => 'text', 'text' => "Request failed. Reference: {$reference}"]],
            'isError' => true,
        ]);
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
