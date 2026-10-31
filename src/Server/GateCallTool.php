<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Classification\Catalog;
use ContextGate\Classification\Ceiling;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\ContractValidator;
use ContextGate\Contract\Field;
use ContextGate\Pipeline\Budget;
use ContextGate\Pipeline\CanaryCheck;
use ContextGate\Pipeline\ClassifyCheck;
use ContextGate\Pipeline\Normalizer;
use ContextGate\Pipeline\Payload;
use ContextGate\Pipeline\Projector;
use ContextGate\Pipeline\Quarantine;
use ContextGate\Pipeline\Redact;
use ContextGate\Pipeline\Relations;
use ContextGate\Pipeline\Transformer;
use ContextGate\Policy\ToolPolicy;
use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Privacy\Redactor;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;
use UnexpectedValueException;

final class GateCallTool implements Method
{
    /** ponytail: the session is the stdio process and its single caller; the session TTL is one hour, no config. */
    private const SESSION_TTL = 3600;

    /** Budget refusals; each flag value is the ReasonCode of its refusal. */
    private const BUDGET_REFUSALS = ['rate.limited', 'budget.session', 'budget.daily'];

    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $caller = Caller::current();
        $name = (string) $request->get('name');
        $registry = new ToolRegistry(config('context-gate.tools', []));
        $tool = $registry->find($name);

        // Hidden and unknown tools share one response (FR-005); the reason goes to the receipt only.
        if ($tool === null || ! $registry->isVisible($name, $caller)) {
            $this->receipt($caller, $name, $registry->isPinMismatch($name) ? ReasonCode::ToolPinMismatch : ReasonCode::PolicyHidden);

            throw new JsonRpcException("Tool [{$name}] not found.", -32602, $request->id);
        }

        $arguments = (array) $request->get('arguments', []);

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
            $this->observe('fetch');
            $response = $tool->handle($arguments);
            // A secret ceiling or a tool without a contract leaves nothing to project (constitution I and III).
            $decoded = $contract === null || $ceiling === Ceiling::Secret ? null : $this->decode((string) $response->content());
        } catch (Throwable) {
            $reference = $this->receipt($caller, $name, ReasonCode::HandlerFailed);

            return $this->failed($request, $reference);
        }

        if ($contract === null || $decoded === null) {
            $this->receipt($caller, $name, ReasonCode::PolicyDenied);

            return $this->refused($request);
        }

        $budget = new Budget(Cache::store(), app(RateLimiter::class), $caller->id, $caller->id, self::SESSION_TTL);

        try {
            $result = $this->firewall($caller, $name, $contract, $ceiling, $budget, $decoded[0]);
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

            // rate.limited tells the caller when the window clears; the other budget refusals say nothing more.
            return $this->refused($request, $budget->retryAfter === null ? null : "Request refused. Retry after {$budget->retryAfter} seconds.");
        }

        $this->observe('receipt');
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

    /**
     * Stages 2 to 10 of R1 in their fixed order. Returns the payload, or the reason the call stops: a canary hit
     * or a failed normalisation or pseudonym transform.
     *
     * @param list<array<string, mixed>> $rows the handler output, before any projection
     */
    private function firewall(Caller $caller, string $name, ContextContract $contract, Ceiling $ceiling, Budget $budget, array $rows): Payload|ReasonCode
    {
        $fields = $contract->fields();
        $table = (new ContractValidator())->tableOf($contract);
        /** @var Model $model */
        $model = new ($contract->model())();
        $key = $model->getKeyName();
        $textFields = array_keys(array_filter($fields, static fn (Field $field): bool => $field->form === Field::TEXT));
        $scope = "session:{$caller->id}";

        // The record identifiers are read before the projection, which may drop the id column the pseudonyms need.
        $ids = [];

        foreach ($rows as $index => $row) {
            if (is_int($row[$key] ?? null) || is_string($row[$key] ?? null)) {
                $ids[$index] = $row[$key];
            }
        }

        /** @var array<string, array<string, mixed>> $map */
        $map = config('context-gate.classification', []);
        $catalog = new Catalog($map);
        $identity = static fn (Payload $next): Payload => $next;

        $this->observe('classify');
        $payload = (new ClassifyCheck($catalog, $ceiling, $table, $fields))->handle(new Payload($rows, array_keys($fields)), $identity);

        $this->observe('project');
        $payload = (new Projector())->handle($payload, $identity);

        $this->observe('relations');

        // A related day or week pseudonym would need a key that the relation does not carry: refused, not emitted.
        if ($this->relatesToDayOrWeekPseudonym($fields)) {
            return ReasonCode::PseudonymUnknown;
        }

        $payload = (new Relations($fields, $catalog, $ceiling, new Pseudonymizer(), $scope, $caller->id))->handle($payload, $identity);

        $this->observe('transform');
        try {
            $payload = $this->transform($payload, $fields, $name, $caller, $table, $key, $ids);
        } catch (Throwable) {
            return ReasonCode::PseudonymKeyFail;
        }

        $this->observe('normalize');
        try {
            $payload = (new Normalizer($textFields))->handle($payload, $identity);
        } catch (Throwable) {
            return ReasonCode::PolicyDenied;
        }

        $this->observe('redact');
        $payload = (new Redact(new Redactor(), $textFields))->handle($payload, $identity);

        $this->observe('canary');
        /** @var list<string> $canaries */
        $canaries = config('context-gate.canaries.values', []);
        $payload = (new CanaryCheck($canaries))->handle($payload, $identity);

        if (in_array(CanaryCheck::FLAG, $payload->flags, true)) {
            return ReasonCode::CanaryHit;
        }

        $this->observe('quarantine');
        $payload = (new Quarantine($name, $textFields))->handle($payload, $identity);

        $this->observe('budget');

        return $budget->handle($payload, $identity);
    }

    /**
     * Stage 5: one transformer per pseudonym, bucket, date or aggregate field, so each pseudonym gets its own
     * scope key. Plain, text and relation fields pass through.
     *
     * @param array<string, Field> $fields
     * @param array<int, int|string> $ids record identifiers by row position
     */
    private function transform(Payload $payload, array $fields, string $name, Caller $caller, string $table, string $key, array $ids): Payload
    {
        foreach ($fields as $column => $field) {
            if (! in_array($column, $payload->fields, true) || in_array($field->form, [Field::PLAIN, Field::TEXT, Field::RELATION], true)) {
                continue;
            }

            $payload = (new Transformer(new Pseudonymizer(), $table, $this->scopeKey($field, $name, $caller), [$column => $field], $key, $caller->id, $ids))
                ->handle($payload, static fn (Payload $next): Payload => $next);
        }

        return $payload;
    }

    /** @param array<string, Field> $fields the parent contract's fields */
    private function relatesToDayOrWeekPseudonym(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($field->form !== Field::RELATION) {
                continue;
            }

            /** @var class-string<ContextContract> $relatedClass */
            $relatedClass = $field->options['contract'];

            foreach ((new $relatedClass())->fields() as $relatedField) {
                if ($relatedField->form === Field::PSEUDONYM && ($relatedField->options['scope'] ?? 'session') !== 'session') {
                    return true;
                }
            }
        }

        return false;
    }

    /** The scope as stored and resolved: session:{caller}, or {day|week}:{tool} with the caller bound by the store. */
    private function scopeKey(Field $field, string $name, Caller $caller): string
    {
        /** @var string $scope */
        $scope = $field->options['scope'] ?? 'session';

        return $scope === 'session' ? "session:{$caller->id}" : "{$scope}:{$name}";
    }

    /** @return array{list<array<string, mixed>>, bool} the rows, and whether the handler returned a list */
    private function decode(string $text): array
    {
        $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new UnexpectedValueException('Handler output is not JSON.');
        }

        $isList = array_is_list($decoded);
        $rows = $isList ? $decoded : [$decoded];

        if (array_filter($rows, is_array(...)) !== $rows) {
            throw new UnexpectedValueException('Handler output rows must be JSON objects.');
        }

        /** @var list<array<string, mixed>> $rows */
        return [$rows, $isList];
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
            $record = (new Pseudonymizer())->resolve($value, $this->scopeKey($field, $name, $caller), $caller->id);
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

    /** Test seam: a bound observer is told each stage as it starts. Not bound in production. */
    private function observe(string $stage): void
    {
        if (app()->bound('context-gate.stage_observer')) {
            app('context-gate.stage_observer')($stage);
        }
    }
}
