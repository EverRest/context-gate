<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Classification\Catalog;
use ContextGate\Classification\Ceiling;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\ContractValidator;
use ContextGate\Contract\Field;
use ContextGate\Pipeline\ClassifyCheck;
use ContextGate\Pipeline\Payload;
use ContextGate\Pipeline\Projector;
use ContextGate\Policy\ToolPolicy;
use ContextGate\Privacy\Pseudonymizer;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;
use UnexpectedValueException;

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

        // R5: a pseudonym argument is resolved on the server before validation. An unknown or expired one is
        // refused without saying whether a record exists (FR-010).
        foreach ($this->pseudonymFields($name) as $column => $field) {
            if (! array_key_exists($column, $arguments)) {
                continue;
            }

            // A present but non-string value cannot be a pseudonym: it is refused like an unknown one, not passed on.
            $record = is_string($arguments[$column]) ? $this->resolvePseudonym($field, $caller, $name, $arguments[$column]) : null;

            if ($record === null) {
                Receipt::write($caller->id, $name, ReasonCode::PseudonymUnknown);

                return JsonRpcResponse::result($request->id, [
                    'content' => [['type' => 'text', 'text' => 'Request refused.']],
                    'isError' => true,
                ]);
            }

            $arguments[$column] = $record['id'];
        }

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

        // FR-015: the exception message stays out of the response and the receipt; only the reference reaches the caller.
        try {
            $response = $tool->handle($arguments);
            $contract = $this->contractFor($name);
            $text = $contract === null ? null : $this->project($contract, (string) $response->content());
        } catch (Throwable) {
            $reference = Receipt::write($caller->id, $name, ReasonCode::HandlerFailed);

            return JsonRpcResponse::result($request->id, [
                'content' => [['type' => 'text', 'text' => "Request failed. Reference: {$reference}"]],
                'isError' => true,
            ]);
        }

        // A tool without a contract is refused: nothing it returned may leave (constitution III).
        if ($text === null) {
            Receipt::write($caller->id, $name, ReasonCode::PolicyDenied);

            return JsonRpcResponse::result($request->id, [
                'content' => [['type' => 'text', 'text' => 'Request refused.']],
                'isError' => true,
            ]);
        }

        Receipt::write($caller->id, $name);

        return JsonRpcResponse::result($request->id, [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => false,
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

        /** @var string $scope */
        $scope = $field->options['scope'];
        $key = $scope === 'session' ? "session:{$caller->id}" : "{$scope}:{$name}";

        try {
            $record = (new Pseudonymizer())->resolve($value, $key, $caller->id);
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

    /**
     * R1 stages 2 and 3: the handler output must be a JSON object or list of objects; only contract fields stay.
     * Returns null when the configured ceiling is secret: nothing may leave in that case (constitution I).
     */
    private function project(ContextContract $contract, string $text): ?string
    {
        $ceiling = Ceiling::from((string) config('context-gate.firewall.ceiling', Ceiling::Internal->value));

        if ($ceiling === Ceiling::Secret) {
            return null;
        }

        $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new UnexpectedValueException('Handler output is not JSON.');
        }

        $isList = array_is_list($decoded);
        $rows = $isList ? $decoded : [$decoded];

        if (array_filter($rows, is_array(...)) !== $rows) {
            throw new UnexpectedValueException('Handler output rows must be JSON objects.');
        }

        /** @var array<string, array<string, mixed>> $map */
        $map = config('context-gate.classification', []);
        $table = (new ContractValidator())->tableOf($contract);
        $fields = $contract->fields();

        $projected = (new ClassifyCheck(new Catalog($map), $ceiling, $table, $fields))->handle(
            new Payload($rows, array_keys($fields)),
            static fn (Payload $next): Payload => (new Projector())->handle($next, static fn (Payload $done): Payload => $done),
        );

        return json_encode($isList ? $projected->rows : ($projected->rows[0] ?? []), JSON_THROW_ON_ERROR);
    }
}
