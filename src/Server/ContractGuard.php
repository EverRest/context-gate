<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Classification\Catalog;
use ContextGate\Contract\ContextContract;
use ContextGate\Contract\ContractValidator;
use ContextGate\Contract\Field;

/**
 * The load rules of a contract, checked on every call but computed once per contract class and classification map.
 *
 * @internal May change without notice.
 */
final class ContractGuard
{
    /** @var array<string, string|null> */
    private static array $seen = [];

    /** The rule the contract breaks under the configured classification, or null when it is valid. */
    public static function violation(ContextContract $contract): ?string
    {
        /** @var array<string, array<string, mixed>> $map */
        $map = config('context-gate.classification', []);
        $key = $contract::class.'|'.md5(serialize($map)).'|'.self::fingerprint($contract);

        if (! array_key_exists($key, self::$seen)) {
            self::$seen[$key] = (new ContractValidator())->violation($contract, new Catalog($map));
        }

        return self::$seen[$key];
    }

    /** Forgets every verdict; for long-lived workers and tests. */
    public static function clear(): void
    {
        self::$seen = [];
    }

    /** What the verdict depends on besides the classification: table, field forms, related contracts one level down. */
    private static function fingerprint(ContextContract $contract): string
    {
        $parts = [(new ContractValidator())->tableOf($contract), self::forms($contract)];

        foreach ($contract->fields() as $field) {
            $class = $field->options['contract'] ?? null;

            if ($field->form === Field::RELATION && is_string($class)) {
                $related = app($class);
                $parts[] = [$related::class, (new ContractValidator())->tableOf($related), self::forms($related)];
            }
        }

        return md5(serialize($parts));
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    private static function forms(ContextContract $contract): array
    {
        return array_map(static fn (Field $f): array => [$f->form, $f->options], $contract->fields());
    }
}
