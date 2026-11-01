<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\ContractValidator;
use ContextGate\Contract\Field;
use ContextGate\Privacy\Pseudonymizer;
use Throwable;

/** R5: a pseudonym argument is resolved on the server before validation (FR-010). */
final class PseudonymArguments
{
    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|null the arguments with each pseudonym replaced by its record id; null when one
     *                                   is unknown, expired, of the wrong kind or unreadable
     */
    public static function resolve(array $arguments, ContextContract $contract, Caller $caller, string $name): ?array
    {
        $fields = array_filter($contract->fields(), static fn (Field $field): bool => $field->form === Field::PSEUDONYM);

        foreach ($fields as $column => $field) {
            if (! array_key_exists($column, $arguments)) {
                continue;
            }

            // A present but non-string value cannot be a pseudonym: it is refused like an unknown one, not passed on.
            $record = is_string($arguments[$column]) ? self::record($field, $contract, $caller, $name, $arguments[$column]) : null;

            if ($record === null) {
                return null;
            }

            $arguments[$column] = $record['id'];
        }

        return $arguments;
    }

    /** @return array{table: string, id: string}|null null when unknown, expired or unreadable */
    private static function record(Field $field, ContextContract $contract, Caller $caller, string $name, string $value): ?array
    {
        $prefix = $field->options['prefix'] ?? null;

        // A pseudonym must carry this column's prefix and belong to this contract's table; otherwise it is refused.
        if (! is_string($prefix) || ! str_starts_with($value, $prefix.'_')) {
            return null;
        }

        try {
            $record = app(Pseudonymizer::class)->resolve($value, FirewallRunner::scopeKey($field, $name, $caller), $caller->id);
        } catch (Throwable) {
            // Fail closed: an unreadable scope refuses exactly like an unknown pseudonym.
            return null;
        }

        return $record !== null && $record['table'] === (new ContractValidator())->tableOf($contract) ? $record : null;
    }
}
